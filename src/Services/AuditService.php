<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use GuzzleHttp\Client;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
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
 * Each cron run calls SiteAuditSpiderTask, which calls processTick().
 * processTick() does exactly ONE of the following:
 *
 *   A) Seed phase  — if the session has SitemapUrl and no pages yet:
 *                    call SpiderService::parseSitemap(), queue all <loc> URLs,
 *                    then return. Next tick starts crawling.
 *
 *   B) Crawl phase — fetch the oldest IsCrawled=false AuditPage for the
 *                    oldest running AuditSession, crawl it, persist metrics,
 *                    run AI cannibalization, then return.
 *
 *   C) Finish      — if session.isExhausted(), mark it completed.
 *
 * Multiple AuditSessions can be running concurrently; each tick services
 * the session with the lowest ID that still has pending pages (round-robin
 * across sessions is a future enhancement — current model: FIFO by session ID).
 *
 * ── Session selection priority ────────────────────────────────────────────────
 *   1. running sessions first (oldest ID)
 *   2. pending sessions are transitioned to running and seeded
 *
 * YAML config:
 *   Kalakotra\SiteSpider\Services\AuditService:
 *     ai_cannibalization_enabled: true
 *     crawl_delay_ms: 0          # 0 = no delay (cron handles scheduling)
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
        private readonly Client           $httpClient,
        private readonly LoggerInterface  $logger,
    ) {}

    // ── Public: single-tick entry point ───────────────────────────────────────

    /**
     * Process one page for one session. Called once per cron tick.
     *
     * Returns a human-readable summary of what was done (for task output).
     */
    public function processTick(): string
    {
        // ── Find the active session ───────────────────────────────────────────
        $session = $this->resolveActiveSession();

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

        // ── B) Crawl phase: pick next pending page ────────────────────────────
        $page = $session->nextPendingPage();

        if (!$page) {
            return $this->finaliseSession($session);
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
        $session->TotalPages   = AuditPage::get()->filter('AuditSessionID', $session->ID)->count();
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

    /**
     * Mark session as completed and compute final stats.
     */
    private function finaliseSession(AuditSession $session): string
    {
        $pages = AuditPage::get()->filter('AuditSessionID', $session->ID);

        $session->Status      = 'completed';
        $session->FinishedAt  = date('Y-m-d H:i:s');
        $session->TotalPages  = $pages->count();
        $session->CrawledPages= $pages->filter('IsCrawled', true)->count();
        $session->TotalBroken = $pages->filter('HttpStatus', 404)->count();
        $session->TotalOrphans= $pages->filter('IsOrphan', true)->count();
        $session->write();

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

                $meta = $this->spider->extractMetadata($html);

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
                    $this->analyzeOnPageSEO($page, $meta);
                }

                if ($aiCannibal && $meta['keywords']) {
                    $this->analyzeKeywordCannibalization($page, $meta, $session->ID);
                }

            } catch (\Throwable $e) {
                $this->logger->warning("SiteSpider fetch error [{$page->URL}]: {$e->getMessage()}");
            }
        }

        $page->syncOrphanFlag();
        $page->write();

        // Optional polite delay (usually 0 in cron model)
        $delayMs = (int) self::config()->get('crawl_delay_ms');
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
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
        int       $sessionId
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
            return; // Nothing to compare against yet
        }

        $previousContext = '';
        foreach ($crawledPages as $crawled) {
            $previousContext .= "  URL: {$crawled->URL}\n"
                . "  Title: {$crawled->PageTitle}\n"
                . "  Keywords: {$crawled->BodyKeywords}\n\n";
        }

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
    private function analyzeOnPageSEO(AuditPage $page, array $meta): void
    {
        $https        = $page->IsHttps        ? 'Yes' : 'No';
        $noindex      = $page->RobotsNoIndex  ? 'Yes' : 'No';
        $nofollow     = $page->RobotsNoFollow ? 'Yes' : 'No';
        $canonical    = $page->CanonicalUrl   ?: '(not set)';
        $ogTitle      = $meta['og_title']       ?: '(missing)';
        $ogDesc       = $meta['og_description'] ?: '(missing)';
        $ogImage      = $meta['og_image']       ?: '(missing)';
        $altTexts     = implode('; ', array_slice($meta['alt_texts'], 0, 5));

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
    private function resolveActiveSession(): ?AuditSession
    {
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
    public function createSession(string $baseUrl, string $sitemapUrl = ''): AuditSession
    {
        $session             = AuditSession::create();
        $session->BaseURL    = rtrim($baseUrl, '/');
        $session->SitemapUrl = $sitemapUrl;
        $session->Status     = 'pending';
        $session->write();

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
}
