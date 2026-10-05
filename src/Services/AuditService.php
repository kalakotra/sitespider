<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use GuzzleHttp\Client;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\AuditTask;
use Kalakotra\SiteSpider\Jobs\SiteAuditCrawlJob;
use Kalakotra\SiteSpider\Services\AuditFindingService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use function array_slice;
use function sprintf;
use function strlen;

/**
 * AuditService — single-tick crawl orchestrator for Kalakotra SiteSpider.
 *
 * ── Cron model ────────────────────────────────────────────────────────────────
 *
 * Each SiteAuditCrawlJob calls processTick() for its specific session.
 * processTick() does exactly ONE of the following:
 *
 *   A) Seed phase  — if the session has SitemapUrl and no pages yet:
 *                    call SpiderService::parseSitemap(), queue all <loc> URLs,
 *                    then return. Next tick starts crawling.
 *
 *   B) Crawl phase — fetch the oldest IsCrawled=false AuditPage for the
 *                    selected running AuditSession, crawl it, persist metrics,
 *                    run AI cannibalization, then return.
 *
 *   C) Finish      — if session.isExhausted(), mark it completed.
 *
 * Multiple AuditSessions can be queued independently; each job remains scoped
 * to one session and one crawl tick.
 *
 * ── Session selection priority ────────────────────────────────────────────────
 *   1. Running sessions are preferred when no session ID is supplied.
 *   2. Queued jobs pass a session ID to keep work isolated.
 *
 * YAML config:
 *   Kalakotra\SiteSpider\Services\AuditService:
 *     ai_cannibalization_enabled: true
 *     crawl_delay_ms: 0          # Optional in-process delay; the queued job delays continuations
 */
class AuditService
{
    use Injectable;
    use Configurable;

    private static bool $ai_cannibalization_enabled = true;
    private static bool $ai_onpage_enabled          = true;
    private static int  $crawl_delay_ms             = 0;

    public function __construct(
        private readonly SpiderService    $spider,
        private readonly AIGatewayService $aiGateway,
        private readonly PageSpeedService $pageSpeed,
        private readonly Client           $httpClient,
        private readonly LoggerInterface  $logger,
    ) {}

    // ── Public: single-tick entry point ───────────────────────────────────────

    /**
    * Process one crawl tick for one session. Queued jobs call this once each.
     *
     * Returns a human-readable summary of what was done (for task output).
     */
    public function processTick(?int $sessionId = null): string
    {
        // ── Find the active session ───────────────────────────────────────────
        $session = $this->resolveActiveSession($sessionId);

        if (!$session) {
            return 'No active sessions to process.';
        }

        // ── A) Seed phase: session just started, sitemap not yet parsed ───────
        if (
            $session->Status === 'pending'
            || ((int) $session->TotalPages === 0 && $session->SitemapUrl)
        ) {
            return $this->seedSession($session);
        }

        if (!$session->SiteFilesChecked) {
            $this->inspectSiteFiles($session);
        }

        // ── B) Crawl phase: pick next pending page ────────────────────────────
        $page = $session->nextPendingPage();

        if (!$page) {
            return $this->finaliseSession($session);
        }

        if (!$this->isRobotsBlocked($page, $session)) {
            $quota = Injector::inst()->get(MemberSeoLimitService::class)
                ->reserveCrawlPage($session, $page);
            if ($quota['status'] === 'limit') {
                $session->Status = 'quota_exceeded';
                $session->QuotaResetAt = $quota['resetAt'];
                $session->CurrentPageID = 0;
                $session->write();
                return sprintf(
                    '[Session #%d] Monthly crawl page limit reached; paused until %s.',
                    $session->ID,
                    $quota['resetAt'],
                );
            }
            if ($quota['status'] === 'busy') {
                return sprintf('[Session #%d] Quota reservation is busy; retrying next tick.', $session->ID);
            }
        }

        // Mark session running (idempotent)
        if ($session->Status !== 'running') {
            $session->Status    = 'running';
            $session->StartedAt = $session->StartedAt ?: date('Y-m-d H:i:s');
            $session->write();
        }

        // Track current page pointer
        $session->CurrentPageID = $page->ID;
        $session->write();

        $this->crawlOnePage($page, $session);

        // Update progress counter
        $session->CrawledPages = (int) $session->CrawledPages + 1;
        $session->TotalPages   = AuditPage::get()->filter(['AuditSessionID' => $session->ID])->count();
        $session->CurrentPageID = 0;
        $session->write();

        // ── C) Check if session is now exhausted ──────────────────────────────
        if ($session->isExhausted()) {
            return $this->finaliseSession($session);
        }

        $remaining = AuditPage::get()
            ->filter(['AuditSessionID' => $session->ID, 'IsCrawled' => false])
            ->count();

        return sprintf(
            '[Session #%d] Crawled: %s (%d/%d) | Queued: %d',
            $session->ID,
            $page->URL,
            $session->CrawledPages,
            $session->TotalPages,
            $remaining,
        );
    }

    // ── Session lifecycle ─────────────────────────────────────────────────────

    /**
     * Seed the session queue:
     *  1. If SitemapUrl is set → parse sitemap, insert AuditPage rows.
     *  2. Otherwise → insert the BaseURL as the only seed page.
     * Then transition status to 'running'.
     */
    private function seedSession(AuditSession $session): string
    {
        $session->Status    = 'running';
        $session->StartedAt = date('Y-m-d H:i:s');
        $session->write();

        $this->inspectSiteFiles($session);

        // Always seed BaseURL so homepage is crawled even when sitemap omits it
        $this->ensureSeedPage($session);

        if ($session->SitemapUrl) {
            $count = $this->spider->parseSitemap($session->SitemapUrl, $session->ID);
            $session->TotalPages = $count + 1;
            $session->write();

            $this->logger->info("SiteSpider session #{$session->ID}: seeded {$count} pages from sitemap + BaseURL.");
            return "[Session #{$session->ID}] Seeded {$count} pages from sitemap + BaseURL: {$session->SitemapUrl}";
        }
        $session->TotalPages = 1;
        $session->write();

        return "[Session #{$session->ID}] No sitemap — seeded from BaseURL: {$session->BaseURL}";
    }

    private function inspectSiteFiles(AuditSession $session): void
    {
        $parts = parse_url((string) $session->BaseURL);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            $session->RobotsTxtStatus = 'invalid URL';
            $session->RobotsTxtAnalysis = 'Cannot inspect site files because the session BaseURL is not a valid absolute URL.';
            $session->LLMsTxtStatus = 'invalid URL';
            $session->LLMsTxtAnalysis = 'Cannot inspect site files because the session BaseURL is not a valid absolute URL.';
            $session->SiteFilesChecked = true;
            $session->write();
            return;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $robotsUrl = $origin . '/robots.txt';
        $llmsUrl = $origin . '/llms.txt';

        $robots = $this->spider->fetchSiteFile($robotsUrl);
        $session->RobotsTxtStatus = (string) $robots['status'];
        $session->RobotsTxtContent = $robots['status'] === 200 ? $robots['content'] : '';
        $session->RobotsTxtAnalysis = $this->analyseRobotsTxt(
            (int) $robots['status'],
            (string) $robots['content'],
            $session->SitemapUrl
        );

        if (!$session->SitemapUrl && preg_match('/^\s*Sitemap:\s*(\S+)/im', (string) $robots['content'], $match)) {
            $session->SitemapUrl = trim($match[1]);
        }

        $llms = $this->spider->fetchSiteFile($llmsUrl);
        $session->LLMsTxtStatus = (string) $llms['status'];
        $session->LLMsTxtAnalysis = $this->analyseLLMsTxt(
            (int) $llms['status'],
            (string) $llms['content']
        );
        $session->SiteFilesChecked = true;

        $session->write();
    }

    private function analyseRobotsTxt(int $status, string $content, string $configuredSitemap): string
    {
        if ($status === 404) {
            return "robots.txt was not found (HTTP 404). This is optional; crawlers generally treat the site as having no robots rules. Add the file if you need to control crawler access.";
        }
        if ($status !== 200) {
            return $status >= 500 || $status === 0
                ? "robots.txt could not be read (HTTP {$status}). Crawling is conservatively blocked until the site's robots policy can be confirmed. Check server availability and permissions."
                : "robots.txt returned HTTP {$status}. Check that it is publicly readable and served as plain text.";
        }

        $lines = preg_split('/\r?\n/', $content) ?: [];
        $allowCount = 0;
        $disallowCount = 0;
        $sitemaps = [];
        $globalBlock = false;
        $hasAgent = false;
        foreach ($lines as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                $hasAgent = true;
            } elseif ($key === 'allow' && $value !== '') {
                $allowCount++;
            } elseif ($key === 'disallow' && $value !== '') {
                $disallowCount++;
                if ($value === '/' && $hasAgent) {
                    $globalBlock = true;
                }
            } elseif ($key === 'sitemap' && filter_var($value, FILTER_VALIDATE_URL)) {
                $sitemaps[] = $value;
            }
        }

        $findings = ["robots.txt is reachable (HTTP 200). Parsed {$allowCount} Allow and {$disallowCount} Disallow rules."];
        if ($globalBlock) {
            $findings[] = 'WARNING: at least one user-agent group has Disallow: /. Verify this is intentional; it can block an entire crawler group.';
        }
        if (!$configuredSitemap && !$sitemaps) {
            $findings[] = 'No Sitemap directive found. Add a Sitemap URL if the site has an XML sitemap.';
        } elseif ($sitemaps) {
            $findings[] = 'Sitemap directive(s): ' . implode(', ', array_unique($sitemaps));
        }
        if (trim($content) === '') {
            $findings[] = 'The file is empty; crawlers are effectively unrestricted.';
        }

        return implode("\n", $findings);
    }

    private function analyseLLMsTxt(int $status, string $content): string
    {
        if ($status === 404) {
            return "llms.txt was not found (HTTP 404). It is optional and is not a confirmed Google ranking factor. Consider adding it as a concise guide to the site's canonical, high-value content for AI systems.";
        }
        if ($status !== 200) {
            return "llms.txt returned HTTP {$status}. Check public availability and permissions.";
        }

        $lines = preg_split('/\r?\n/', trim($content)) ?: [];
        $firstContentLine = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $firstContentLine = trim($line);
                break;
            }
        }
        preg_match_all('/\[[^\]]+\]\(https?:\/\/[^)]+\)/i', $content, $links);
        $sectionCount = preg_match_all('/^#{2,3}\s+/m', $content);
        $findings = ["llms.txt is reachable (HTTP 200); found " . count($links[0]) . ' absolute Markdown link(s) and ' . (int) $sectionCount . ' section heading(s).'];
        if (!str_starts_with($firstContentLine, '# ')) {
            $findings[] = 'Recommendation: start with a level-one Markdown heading naming the site or organisation.';
        }
        if (count($links[0]) === 0) {
            $findings[] = 'Recommendation: add curated absolute links to the most useful canonical pages, with short descriptions.';
        }
        if (strlen(trim($content)) < 80) {
            $findings[] = 'The file is very short; include a brief description and the key content sections that best represent the site.';
        }
        $findings[] = 'llms.txt is an emerging convention, not a guaranteed search-ranking signal.';

        return implode("\n", $findings);
    }

    /**
     * Mark session as completed and compute final stats.
     */
    private function finaliseSession(AuditSession $session): string
    {
        $pages = AuditPage::get()->filter(['AuditSessionID' => $session->ID]);

        $session->Status      = 'completed';
        $session->FinishedAt  = date('Y-m-d H:i:s');
        $session->TotalPages  = $pages->count();
        $session->CrawledPages= $pages->filter(['IsCrawled' => true])->count();
        $session->TotalBroken = $pages->filter(['HttpStatus' => 404])->count();
        $session->TotalOrphans= $pages->filter(['IsOrphan' => true])->count();
        $session->write();

        $project = $session->AuditProject();
        if ($project && $project->exists()) {
            try {
                $findingService = Injector::inst()->createWithArgs(AuditFindingService::class, [
                    'logger' => $this->logger,
                ]);
                $findingService->reconcile($session);
            } catch (\Throwable $e) {
                $this->logger->error("SiteSpider finding reconciliation failed for session #{$session->ID}: {$e->getMessage()}");
            }
            $project->scheduleNextCrawl($session->FinishedAt);
        }

        $this->logger->info(sprintf(
            'SiteSpider session #%d completed. Pages: %d | Broken: %d | Orphans: %d',
            $session->ID,
            $session->TotalPages,
            $session->TotalBroken,
            $session->TotalOrphans,
        ));

        return sprintf(
            '[Session #%d] COMPLETED — %d pages | %d broken | %d orphans | %s',
            $session->ID,
            $session->TotalPages,
            $session->TotalBroken,
            $session->TotalOrphans,
            $session->getDuration(),
        );
    }

    // ── Per-page crawl ────────────────────────────────────────────────────────

    /**
     * Crawl a single AuditPage:
     *   1. checkResponse() — HTTP status
     *   2. On 200: fetch body, extract metadata, discover + persist links
     *   3. AI cannibalization analysis
     *   4. Mark IsCrawled = true
     */
    private function crawlOnePage(AuditPage $page, AuditSession $session): void
    {
        $this->logger->info("SiteSpider crawling [{$session->ID}]: {$page->URL}");

        if ($this->isRobotsBlocked($page, $session)) {
            $page->RobotsBlocked = true;
            $page->HttpStatus = 0;
            $page->CrawledAt = date('Y-m-d H:i:s');
            $page->IsCrawled = true;
            $page->write();
            $this->logger->info("SiteSpider skipped robots-disallowed page: {$page->URL}");
            return;
        }

        // ── Recrawl context (load BEFORE crawling to capture previous task state) ──
        // $page->NeedsRecrawl is still true at this point — will be reset by generateTasks()
        $recrawlCtx = $this->loadPreviousTaskContext($page);

        // ── Step 1: HTTP status ───────────────────────────────────────────────
        $check                = $this->spider->checkResponse($page->URL);
        $page->HttpStatus     = $check['status'];
        $page->CrawledAt      = date('Y-m-d H:i:s');
        $page->IsCrawled      = true;
        $page->ResponseTimeMs = round($check['latency'], 2);
        $page->IsHttps        = str_starts_with($page->URL, 'https://');

        if ($check['redirect']) {
            $page->RedirectTarget = $check['redirect'];
        }

        // ── Step 2: Full fetch on 200 ─────────────────────────────────────────
        if ($check['status'] === 200) {
            try {
                $response = $this->httpClient->get($page->URL, [
                    'timeout'     => 30,
                    'headers'     => ['User-Agent' => self::config()->get('user_agent') ?? 'KalakotraSiteSpider/2.0'],
                    'http_errors' => false,
                ]);

                $html = (string) $response->getBody();

                $meta = $this->spider->extractMetadata($html, $page->URL);

                $page->PageTitle              = $meta['title'];
                $page->MetaDescription        = $meta['description'];
                $page->H1                     = $meta['h1'];
                $page->BodyKeywords           = $meta['keywords'];
                $page->RobotsNoIndex          = $meta['robots_noindex'];
                $page->RobotsNoFollow         = $meta['robots_nofollow'];
                $page->CanonicalUrl           = $meta['canonical'];
                $page->TitleLength            = $meta['title_length'];
                $page->MetaDescriptionLength  = $meta['meta_description_length'];
                $page->H1Count                = $meta['h1_count'];
                $page->H2Count                = $meta['h2_count'];
                $page->WordCount              = $meta['word_count'];
                $page->ImagesWithoutAlt       = $meta['images_without_alt'];
                $page->OgTitle                = $meta['og_title'];
                $page->OgDescription          = $meta['og_description'];
                $page->OgImage                = $meta['og_image'];
                // Extended SEO metrics
                $page->H3Count              = $meta['h3_count'];
                $page->H4Count              = $meta['h4_count'];
                $page->H5Count              = $meta['h5_count'];
                $page->H6Count              = $meta['h6_count'];
                $page->HeadingOrderIssue    = $meta['heading_order_issue'];
                $page->HasStructuredData    = $meta['has_structured_data'];
                $page->StructuredDataTypes  = substr(implode(', ', $meta['structured_data_types']), 0, 512);
                $page->StructuredDataErrors = substr($meta['structured_data_errors'], 0, 1000);
                $page->HasViewportMeta      = $meta['has_viewport_meta'];
                $page->HreflangCount        = $meta['hreflang_count'];
                $page->ExternalLinksCount   = $meta['external_links_count'];

                // Discover links and persist edges
                try {
                    $links = $this->spider->discoverLinks($html, $page->URL, $session->ID);
                    $this->spider->persistLinks($page, $links, $session->ID);
                } catch (\Throwable $e) {
                    $this->logger->warning("SiteSpider link persist error [{$page->URL}]: {$e->getMessage()}");
                }

                // ── Step 3: AI analysis ───────────────────────────────────────
                $aiOnpage = (bool) self::config()->get('ai_onpage_enabled');
                $aiCannibal = (bool) self::config()->get('ai_cannibalization_enabled');
                $this->logger->debug(sprintf(
                    'SiteSpider AI flags — onpage:%s cannibal:%s keywords:%s [%s]',
                    $aiOnpage ? 'ON' : 'OFF',
                    $aiCannibal ? 'ON' : 'OFF',
                    $meta['keywords'] ? 'YES' : 'NO',
                    $page->URL
                ));

                if ($aiOnpage) {
                    $this->analyzeOnPageSEO($page, $meta, $recrawlCtx);
                }

                if ($aiCannibal && $meta['keywords']) {
                    $this->analyzeKeywordCannibalization($page, $meta, $session->ID, $recrawlCtx);
                } elseif ($aiCannibal) {
                    $page->KeywordCannibalizationWarning = null;
                }

            } catch (\Throwable $e) {
                $this->logger->warning("SiteSpider fetch error [{$page->URL}]: {$e->getMessage()}");
            }
        }

        // ── PSI Analysis (if enabled) ──────────────────────────────────────────────────────────────
        if ($this->pageSpeed->isEnabled()) {
            try {
                $this->pageSpeed->analyse($page); // sets PSI_* fields, no write yet
            } catch (\Throwable $e) {
                $this->logger->warning("SiteSpider PSI error [{$page->URL}]: {$e->getMessage()}");
            }
        }

        $page->syncOrphanFlag();
        $page->write();

        // ── Generate task checklist ───────────────────────────────────────────────────────────────
        $this->generateTasks($page);

        // Optional polite delay (usually 0 in cron model)
        $delayMs = (int) self::config()->get('crawl_delay_ms');
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function isRobotsBlocked(AuditPage $page, AuditSession $session): bool
    {
        $robotsStatus = (int) $session->RobotsTxtStatus;
        return $robotsStatus === 0
            || $robotsStatus >= 500
            || !$this->spider->isAllowedByRobots((string) $session->RobotsTxtContent, $page->URL);
    }

    // ── AI Cannibalization Analysis ───────────────────────────────────────────

    /**
     * Compare this page's keywords against all other crawled pages in the
     * same session. Stores AI warning text on AuditPage if overlap found.
     *
     * Context is scoped to the current AuditSession — only pages from THIS
     * session are compared, ensuring site-specific relevance.
     */
    private function analyzeKeywordCannibalization(
        AuditPage $page,
        array     $meta,
        int       $sessionId,
        array     $recrawlCtx = []
    ): void {
        $currentKeywords = $meta['keywords'];
        $altTexts        = implode('; ', array_slice($meta['alt_texts'], 0, 10));

        // Build context from all previously crawled pages in THIS session
        $crawledPages = AuditPage::get()
            ->filter([
                'AuditSessionID' => $sessionId,
                'IsCrawled'      => true,
            ])
            ->exclude('ID', [(int) $page->ID])
            ->exclude('BodyKeywords', ['', null])
            ->sort('ID ASC')
            ->limit(50); // cap context to last 50 pages to control token count

        if (!$crawledPages->exists()) {
            $page->KeywordCannibalizationWarning = null;
            return; // Nothing to compare against yet
        }

        $previousContext = '';
        foreach ($crawledPages as $crawled) {
            $previousContext .= "  URL: {$crawled->URL}\n"
                . "  Title: {$crawled->PageTitle}\n"
                . "  Keywords: {$crawled->BodyKeywords}\n\n";
        }

        $recrawlSection = $this->buildRecrawlPromptSection($recrawlCtx);
        $prompt = <<<PROMPT
        You are an SEO expert auditing a website for keyword cannibalization.

        CURRENT PAGE:
        URL: {$page->URL}
        Title: {$page->PageTitle}
        H1: {$page->H1}
        Top Keywords: {$currentKeywords}
        Image Alt Texts: {$altTexts}

        OTHER PAGES ALREADY CRAWLED IN THIS SESSION:
        {$previousContext}
        {$recrawlSection}
        TASK:
        1. Does the current page compete for the same primary keyword(s) as any
           other page listed above? If yes, name the competing URL(s) and overlapping
           keyword(s) specifically.
        2. Are the image alt texts consistent with the page's main topic? Flag mismatches.
        3. If there is NO cannibalization risk, respond with exactly: NO_ISSUE
        4. Keep your response under 200 words. Be specific and actionable.
        PROMPT;

        try {
            $aiResponse = $this->aiGateway->ask($prompt, [
                'caller_class'   => self::class,
                'caller_context' => 'sitespider-cannibalization',
            ]);

            $responseText = trim($aiResponse->content);
            $page->KeywordCannibalizationWarning = null;

            if ($responseText !== 'NO_ISSUE' && strlen($responseText) > 5) {
                $page->KeywordCannibalizationWarning = $responseText;
                $this->logger->info("SiteSpider AI warning for {$page->URL}");
            }

        } catch (AIProviderException $e) {
            $this->logger->warning("SiteSpider AI cannibalization failed for {$page->URL}: {$e->getMessage()}");
        }
    }

    // ── AI On-Page SEO Analysis ───────────────────────────────────────────────

    /**
     * Comprehensive single-page SEO analysis covering all measurable on-page
     * and technical signals. Stores a structured report on AuditPage.
     */
    private function analyzeOnPageSEO(AuditPage $page, array $meta, array $recrawlCtx = []): void
    {
        $https        = $page->IsHttps        ? 'Yes' : 'No';
        $noindex      = $page->RobotsNoIndex  ? 'Yes' : 'No';
        $nofollow     = $page->RobotsNoFollow ? 'Yes' : 'No';
        $canonical    = $page->CanonicalUrl   ?: '(not set)';
        $ogTitle      = $meta['og_title']       ?: '(missing)';
        $ogDesc       = $meta['og_description'] ?: '(missing)';
        $ogImage      = $meta['og_image']       ?: '(missing)';
        $altTexts     = implode('; ', array_slice($meta['alt_texts'], 0, 5));

        $recrawlSection = $this->buildRecrawlPromptSection($recrawlCtx);

        $prompt = <<<PROMPT
        You are a senior SEO auditor. Analyse the following crawled page and produce a
        structured, actionable report. Be specific — name exact problems and fixes.

        PAGE DATA:
        URL:                    {$page->URL}
        HTTPS:                  {$https}
        HTTP Status:            {$page->HttpStatus}
        Response Time (ms):     {$page->ResponseTimeMs}

        TITLE:                  "{$page->PageTitle}"
        Title Length (chars):   {$page->TitleLength}  (optimal: 50–60)

        META DESCRIPTION:       "{$page->MetaDescription}"
        Desc Length (chars):    {$page->MetaDescriptionLength}  (optimal: 120–160)

        H1 Tags Found:          {$page->H1Count}  (ideal: exactly 1)
        H1 Text:                "{$page->H1}"
        H2 Tags Found:          {$page->H2Count}

        Word Count:             {$page->WordCount}
        Top Keywords:           {$meta['keywords']}

        Canonical URL:          {$canonical}
        Robots noindex:         {$noindex}
        Robots nofollow:        {$nofollow}

        OG Title:               {$ogTitle}
        OG Description:         {$ogDesc}
        OG Image:               {$ogImage}

        Images WITHOUT alt:     {$page->ImagesWithoutAlt}
        Sample alt texts:       {$altTexts}

        Inbound links:          {$page->InboundLinksCount}
        Outbound links:         {$page->OutboundLinksCount}

        REPORT FORMAT (use exactly these sections, skip none):
        [TECHNICAL]
        - HTTPS, response time, noindex/nofollow issues, canonical

        [ON-PAGE]
        - Title: length, quality, keyword presence
        - Meta Description: length, quality, call-to-action
        - Headings: H1 count issues, H1/H2 consistency with keywords
        - Content: word count adequacy, keyword relevance

        [SOCIAL]
        - OG tags: missing or weak fields, image presence

        [IMAGES]
        - Alt text coverage and quality

        [LINKS]
        - Orphan risk, outbound link count

        [PRIORITY FIXES]
        List the top 3 most impactful fixes for this specific page, numbered.
        {$recrawlSection}
        Rules: under 350 words total. Be direct and specific. If a section has no issues, write "OK".
        PROMPT;

        try {
            $aiResponse = $this->aiGateway->ask($prompt, [
                'caller_class'   => self::class,
                'caller_context' => 'sitespider-onpage',
            ]);

            $report = trim($aiResponse->content);
            if (strlen($report) > 5) {
                $page->AiOnPageReport = $report;
                $this->logger->info("SiteSpider AI on-page report for {$page->URL}");
            }

        } catch (AIProviderException $e) {
            $this->logger->warning("SiteSpider AI on-page failed for {$page->URL}: {$e->getMessage()}");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the oldest AuditSession that needs work:
     *   - Status = 'running' with pending pages, OR
     *   - Status = 'pending' (needs seeding)
     */
    private function resolveActiveSession(?int $sessionId = null): ?AuditSession
    {
        if ($sessionId !== null) {
            $session = AuditSession::get()->byID($sessionId);
            return $session && in_array($session->Status, ['pending', 'running'], true)
                ? $session
                : null;
        }

        // Prefer already-running sessions that still have pending pages
        $running = AuditSession::get()
            ->filter('Status', 'running')
            ->sort('ID ASC');

        foreach ($running as $session) {
            if (!$session->isExhausted()) {
                return $session;
            }
            // Exhausted running session — finalise it and continue to next
            $this->finaliseSession($session);
        }

        // Fall back to oldest pending session
        /** @var AuditSession|null */
        return AuditSession::get()
            ->filter('Status', 'pending')
            ->sort('ID ASC')
            ->first();
    }

    /**
     * Ensure $session->BaseURL exists as the seed AuditPage.
     */
    private function ensureSeedPage(AuditSession $session): void
    {
        $exists = AuditPage::get()
            ->filter(['URL' => $session->BaseURL, 'AuditSessionID' => $session->ID])
            ->exists();

        if (!$exists) {
            $seed                = AuditPage::create();
            $seed->URL           = $session->BaseURL;
            $seed->AuditSessionID= $session->ID;
            $seed->IsCrawled     = false;
            $seed->IsFromSitemap = false;
            $seed->write();
        }
    }

    // ── Public factory helpers ────────────────────────────────────────────────

    /**
     * Create a new AuditSession and queue it for processing.
     * The cron will pick it up on the next tick.
     */
    public function createSession(string $baseUrl, string $sitemapUrl = '', bool $noAI = false): AuditSession
    {
        $session             = AuditSession::create();
        $session->BaseURL    = rtrim($baseUrl, '/');
        $session->SitemapUrl = $sitemapUrl;
        $session->Status     = 'pending';
        $session->write();
        SiteAuditCrawlJob::queueSession((int) $session->ID, $noAI);

        $this->logger->info(
            "SiteSpider: created session #{$session->ID} for {$baseUrl}"
            . ($sitemapUrl ? " (sitemap: {$sitemapUrl})" : '')
        );

        return $session;
    }

    /**
     * Link strength score: InboundLinksCount normalised to [0, 100].
     */
    public function getLinkStrengthScore(AuditPage $page, int $sessionId): int
    {
        $max = (int) DB::query(
            "SELECT MAX(InboundLinksCount) FROM KSS_AuditPage WHERE AuditSessionID = {$sessionId}"
        )->value();

        return $max > 0 ? (int) round(($page->InboundLinksCount / $max) * 100) : 0;
    }

    // ── Recrawl context loader ─────────────────────────────────────────────────

    /**
     * Load previous task state for recrawl-aware AI analysis.
     *
     * Called BEFORE the new crawl starts so $page->NeedsRecrawl is still true.
     * Returns empty array on first crawls — no context means standard AI prompts.
     *
     * @return array{is_recrawl: bool, resolved: array, ignored: array}|array{}
     */
    private function loadPreviousTaskContext(AuditPage $page): array
    {
        if (!$page->NeedsRecrawl) {
            return []; // First crawl or not yet fully resolved
        }

        $resolved = [];
        $ignored  = [];

        foreach (AuditTask::get()->filter(['AuditPageID' => $page->ID, 'Status' => 'done']) as $task) {
            $resolved[] = ['type' => (string) $task->Type, 'desc' => (string) $task->Description];
        }

        foreach (AuditTask::get()->filter(['AuditPageID' => $page->ID, 'Status' => 'ignored']) as $task) {
            $ignored[] = ['type' => (string) $task->Type, 'desc' => (string) $task->Description];
        }

        if (empty($resolved) && empty($ignored)) {
            return [];
        }

        $this->logger->info(sprintf(
            'SiteSpider recrawl context loaded: %d resolved, %d ignored tasks for %s',
            count($resolved),
            count($ignored),
            $page->URL
        ));

        return [
            'is_recrawl' => true,
            'resolved'   => $resolved,
            'ignored'    => $ignored,
        ];
    }

    private function buildRecrawlPromptSection(array $recrawlCtx): string
    {
        if (empty($recrawlCtx['is_recrawl'])) {
            return '';
        }

        $section = "\nPREVIOUS AUDIT — RE-CRAWL VERIFICATION:\n";
        foreach ($recrawlCtx['resolved'] ?? [] as $item) {
            $section .= "User marked FIXED; verify against current page data: [{$item['type']}] {$item['desc']}\n";
        }
        foreach ($recrawlCtx['ignored'] ?? [] as $item) {
            $section .= "User chose to IGNORE; do not report this issue again: [{$item['type']}] {$item['desc']}\n";
        }
        $section .= "Confirm each fixed issue as fixed or still present. Report newly detected issues only when supported by current page data.\n";

        return $section;
    }

    // ── Task generation ──────────────────────────────────────────────────────────────────

    /**
     * Auto-generate AuditTask checklist items for a crawled page.
     * Idempotent: existing 'open' tasks are deleted and regenerated each crawl.
     * 'done' and 'ignored' tasks are preserved so user work is not lost.
     */
    private function generateTasks(AuditPage $page): void
    {
        // Suppress recrawl scheduling while task rows are being reconciled.
        $page->IsCrawled = false;
        $page->NeedsRecrawl = false;
        $page->write();

        // Remove previous open tasks only (preserve user-resolved tasks)
        foreach (AuditTask::get()->filter(['AuditPageID' => $page->ID, 'Status' => 'open']) as $old) {
            $old->delete();
        }

        $tasks = [];

        if ($page->HttpStatus === 200) {

            // ─ Title ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────
            if (!(int) $page->TitleLength) {
                $tasks[] = ['type' => 'missing_title', 'priority' => 'high',
                    'desc' => 'Page has no <title> tag.'];
            } elseif ((int) $page->TitleLength < 30) {
                $tasks[] = ['type' => 'title_too_short', 'priority' => 'medium',
                    'desc' => "Title is {$page->TitleLength} characters — minimum recommended is 30."];
            } elseif ((int) $page->TitleLength > 60) {
                $tasks[] = ['type' => 'title_too_long', 'priority' => 'medium',
                    'desc' => "Title is {$page->TitleLength} characters — trim to 60 to avoid truncation in SERPs."];
            }

            // ─ Meta Description ─────────────────────────────────────────────────────────────────────────────────────
            if (!(int) $page->MetaDescriptionLength) {
                $tasks[] = ['type' => 'missing_meta', 'priority' => 'high',
                    'desc' => 'No meta description found. Write a compelling 120–160 character summary.'];
            } elseif ((int) $page->MetaDescriptionLength > 160) {
                $tasks[] = ['type' => 'meta_too_long', 'priority' => 'low',
                    'desc' => "Meta description is {$page->MetaDescriptionLength} chars — trim to 160."];
            }

            // ─ H1 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
            if (!(int) $page->H1Count) {
                $tasks[] = ['type' => 'missing_h1', 'priority' => 'high',
                    'desc' => 'Page has no H1 tag. Add exactly one H1 that describes the main topic.'];
            } elseif ((int) $page->H1Count > 1) {
                $tasks[] = ['type' => 'multiple_h1', 'priority' => 'medium',
                    'desc' => "Page has {$page->H1Count} H1 tags — only one H1 is recommended per page."];
            }

            // ─ Heading order ────────────────────────────────────────────────────────────────────────────────────────
            if ($page->HeadingOrderIssue) {
                $tasks[] = ['type' => 'heading_order', 'priority' => 'low',
                    'desc' => 'Heading hierarchy skips levels (e.g. H1 → H3 without H2). Fix the heading structure for accessibility and SEO.'];
            }

            // ─ Images ────────────────────────────────────────────────────────────────────────────────────────────────
            if ((int) $page->ImagesWithoutAlt > 0) {
                $tasks[] = ['type' => 'missing_alt', 'priority' => 'medium',
                    'desc' => "{$page->ImagesWithoutAlt} image(s) missing alt text. Add descriptive alt attributes."];
            }

            // ─ Response time ────────────────────────────────────────────────────────────────────────────────────────
            if ((float) $page->ResponseTimeMs > 2000) {
                $ms = (int) round((float) $page->ResponseTimeMs);
                $tasks[] = ['type' => 'slow_response', 'priority' => 'high',
                    'desc' => "Server response time is {$ms}ms — target is under 2000ms. Review caching and server config."];
            }

            // ─ Core Web Vitals ─────────────────────────────────────────────────────────────────────────────────────
            if ($page->CWV_LCP_Rating === 'poor') {
                $tasks[] = ['type' => 'cwv_poor_lcp', 'priority' => 'high',
                    'desc' => "LCP is {$page->CWV_LCP}ms (Poor >4000ms). Optimise the largest element, use CDN, eliminate render-blocking resources."];
            }
            if ($page->CWV_CLS_Rating === 'poor') {
                $tasks[] = ['type' => 'cwv_poor_cls', 'priority' => 'high',
                    'desc' => "CLS score is {$page->CWV_CLS} (Poor >0.25). Fix layout shifts: set size attributes on images/ads, avoid injecting content above the fold."];
            }
            if ($page->CWV_INP_Rating === 'poor') {
                $tasks[] = ['type' => 'cwv_poor_inp', 'priority' => 'high',
                    'desc' => "INP is {$page->CWV_INP}ms (Poor >500ms). Reduce long JS tasks, yield to main thread, use web workers."];
            }

            // ─ AI Cannibalization ──────────────────────────────────────────────────────────────────────────────────
            if ($page->KeywordCannibalizationWarning) {
                $tasks[] = ['type' => 'cannibalization', 'priority' => 'high',
                    'desc' => (string) $page->KeywordCannibalizationWarning];
            }

            // ─ Robots ────────────────────────────────────────────────────────────────────────────────────────────────
            if ($page->RobotsNoIndex) {
                $tasks[] = ['type' => 'noindex', 'priority' => 'high',
                    'desc' => 'Page has robots noindex — it will not appear in search results. Verify this is intentional.'];
            }

            // ─ Orphan ────────────────────────────────────────────────────────────────────────────────────────────────
            if ($page->IsOrphan) {
                $tasks[] = ['type' => 'orphan', 'priority' => 'medium',
                    'desc' => 'No internal links point to this page. Add at least one contextual link from a related page.'];
            }

            // ─ Structured Data ────────────────────────────────────────────────────────────────────────────────────────
            if (!$page->HasStructuredData) {
                $tasks[] = ['type' => 'missing_structured_data', 'priority' => 'low',
                    'desc' => 'No JSON-LD structured data detected. Add Schema.org markup (Article, Product, BreadcrumbList…) to enable rich results in Google Search.'];
            }

            // ─ Viewport ─────────────────────────────────────────────────────────────────────────────────────────────
            if (!$page->HasViewportMeta) {
                $tasks[] = ['type' => 'no_viewport', 'priority' => 'high',
                    'desc' => 'Missing <meta name="viewport"> — page will not render correctly on mobile devices.'];
            }

            // ─ PageSpeed Insights ──────────────────────────────────────────────────────────────────────────────────
            if ($page->PSI_MobileScore && (int) $page->PSI_MobileScore < 50) {
                $tasks[] = ['type' => 'low_psi_mobile', 'priority' => 'high',
                    'desc' => "Mobile PageSpeed score is {$page->PSI_MobileScore}/100. Run Lighthouse for a detailed improvement plan."];
            }
            if ($page->PSI_DesktopScore && (int) $page->PSI_DesktopScore < 70) {
                $tasks[] = ['type' => 'low_psi_desktop', 'priority' => 'medium',
                    'desc' => "Desktop PageSpeed score is {$page->PSI_DesktopScore}/100."];
            }

        } elseif ($page->HttpStatus === 404) {
            $tasks[] = ['type' => 'broken_link', 'priority' => 'high',
                'desc' => 'Page returns 404 Not Found. Fix the page or create a 301 redirect to the correct URL.'];

        } elseif ($page->HttpStatus >= 301 && $page->HttpStatus <= 308) {
            $redirect = $page->RedirectTarget ?: '(unknown destination)';
            $tasks[] = ['type' => 'redirect', 'priority' => 'low',
                'desc' => "Page redirects to {$redirect}. Update all internal links to point directly to the destination to save a redirect hop."];
        }

        foreach ($tasks as $taskData) {
            $ignoredTask = AuditTask::get()->filter([
                'AuditPageID' => $page->ID,
                'Type' => $taskData['type'],
                'Status' => 'ignored',
            ])->first();
            if ($ignoredTask) {
                continue;
            }

            $resolvedTask = AuditTask::get()->filter([
                'AuditPageID' => $page->ID,
                'Type' => $taskData['type'],
                'Status' => 'done',
            ])->first();
            if ($resolvedTask) {
                $resolvedTask->Description = $taskData['desc'];
                $resolvedTask->Priority = $taskData['priority'];
                $resolvedTask->Status = 'open';
                $resolvedTask->write();
                continue;
            }

            $task              = AuditTask::create();
            $task->AuditPageID = $page->ID;
            $task->Type        = $taskData['type'];
            $task->Priority    = $taskData['priority'];
            $task->Description = $taskData['desc'];
            $task->Status      = 'open';
            $task->write(); // triggers AuditTask::onAfterWrite → syncPageCounters()
        }

        $page->IsCrawled = true;
        $page->NeedsRecrawl = false;
        $page->write();

        $this->logger->info(sprintf(
            'SiteSpider: %d tasks generated for %s',
            count($tasks),
            $page->URL
        ));
    }
}
