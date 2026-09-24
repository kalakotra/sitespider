<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\TransferStats;
use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\PageLink;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * SpiderService — HTTP crawler core for Kalakotra SiteSpider.
 *
 * Public API:
 *  parseSitemap($sitemapUrl, $sessionId)  — fetch + parse XML sitemap,
 *      seed AuditPage queue from <loc> entries.
 *  discoverLinks($html, $baseUrl, $sessionId) — extract internal <a href>
 *      links not yet in the session queue.
 *  checkResponse($url) — HEAD/GET the URL, return status + redirect target.
 *  extractMetadata($html) — pull title/desc/H1/keywords/alts.
 *  persistLinks($sourcePage, $links, $sessionId) — write PageLink edges,
 *      update Inbound/Outbound counters.
 *
 * YAML config:
 *   Kalakotra\SiteSpider\Services\SpiderService:
 *     connect_timeout: 10
 *     request_timeout: 30
 *     user_agent: 'KalakotraSiteSpider/2.0'
 *     respect_nofollow: true
 */
class SpiderService
{
    use Injectable;
    use Configurable;

    private static int    $connect_timeout  = 10;
    private static int    $request_timeout  = 30;
    private static string $user_agent       = 'KalakotraSiteSpider/2.0';
    private static bool   $respect_nofollow = true;

    public function __construct(
        private readonly Client          $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    // ── 0. parseSitemap ───────────────────────────────────────────────────────

    /**
     * Fetch the XML sitemap at $sitemapUrl and pre-populate the AuditPage
     * queue for $sessionId with all <loc> entries.
     *
     * Handles:
     *  - Standard sitemaps  (<urlset><url><loc>…</loc></url></urlset>)
     *  - Sitemap indexes    (<sitemapindex><sitemap><loc>…) — recursed one level
     *
     * Returns the count of new AuditPage rows created.
     */
    public function parseSitemap(string $sitemapUrl, int $sessionId): int
    {
        $this->logger->info("SiteSpider: fetching sitemap {$sitemapUrl}");

        try {
            $response = $this->httpClient->get($sitemapUrl, [
                'timeout'     => (int) self::config()->get('request_timeout'),
                'headers'     => ['User-Agent' => self::config()->get('user_agent')],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning(
                    "SiteSpider: sitemap returned HTTP {$response->getStatusCode()} — {$sitemapUrl}"
                );
                return 0;
            }

            $xml = (string) $response->getBody();

        } catch (\Throwable $e) {
            $this->logger->error("SiteSpider: could not fetch sitemap {$sitemapUrl}: {$e->getMessage()}");
            return 0;
        }

        return $this->processSitemapXml($xml, $sitemapUrl, $sessionId);
    }

    /**
     * Parse raw sitemap XML, handle both <urlset> and <sitemapindex>.
     */
    private function processSitemapXml(string $xml, string $sourceUrl, int $sessionId): int
    {
        // Suppress invalid XML warnings
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($doc === false) {
            $this->logger->warning("SiteSpider: could not parse sitemap XML from {$sourceUrl}");
            return 0;
        }

        $created = 0;
        $rootTag = $doc->getName();

        // ── Sitemap index: recurse into child sitemaps ────────────────────────
        if ($rootTag === 'sitemapindex') {
            foreach ($doc->sitemap as $childSitemap) {
                $loc = (string) ($childSitemap->loc ?? '');
                if ($loc) {
                    $created += $this->parseSitemap($loc, $sessionId);
                }
            }
            return $created;
        }

        // ── Standard urlset ───────────────────────────────────────────────────
        foreach ($doc->url as $url) {
            $loc = trim((string) ($url->loc ?? ''));
            if (!$loc) {
                continue;
            }

            $normalised = $this->normaliseUrl($loc);

            // Skip if already queued for this session
            $exists = AuditPage::get()
                ->filter(['URL' => $normalised, 'AuditSessionID' => $sessionId])
                ->exists();

            if ($exists) {
                continue;
            }

            $page                  = AuditPage::create();
            $page->URL             = $normalised;
            $page->AuditSessionID  = $sessionId;
            $page->IsCrawled       = false;
            $page->IsFromSitemap   = true;
            $page->write();

            $created++;
        }

        $this->logger->info("SiteSpider: sitemap seeded {$created} pages from {$sourceUrl}");

        return $created;
    }

    // ── 1. discoverLinks ─────────────────────────────────────────────────────

    /**
     * Parse $html for ALL internal <a href> links (both new and already-known).
     *
     * Returns every unique internal URL found on the page so that
     * persistLinks() can update InboundLinksCount for ALL targets —
     * including pages already in the session queue from the sitemap.
     * persistLinks() itself decides whether to create a new AuditPage row.
     *
     * @return array<int, array{url: string, anchor: string, nofollow: bool}>
     */
    public function discoverLinks(string $html, string $baseUrl, int $sessionId): array
    {
        $baseParsed = parse_url($baseUrl);
        $baseScheme = $baseParsed['scheme'] ?? 'https';
        $baseHost   = $baseParsed['host']   ?? '';

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath   = new \DOMXPath($dom);
        $anchors = $xpath->query('//a[@href]');
        $found   = [];
        $seen    = [];

        /** @var \DOMElement $anchor */
        foreach ($anchors as $anchor) {
            $href     = trim($anchor->getAttribute('href'));
            $rel      = strtolower((string) $anchor->getAttribute('rel'));
            $nofollow = str_contains($rel, 'nofollow');

            if (
                empty($href)
                || str_starts_with($href, '#')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'javascript:')
            ) {
                continue;
            }

            $absolute = $this->resolveUrl($href, $baseScheme, $baseHost, $baseParsed);
            if ($absolute === null) {
                continue;
            }

            $parsed = parse_url($absolute);
            if (($parsed['host'] ?? '') !== $baseHost) {
                continue; // external link
            }

            $normalised = $this->normaliseUrl($absolute);

            if ($this->isBinaryAsset($normalised)) {
                continue;
            }

            if (isset($seen[$normalised])) {
                continue;
            }
            $seen[$normalised] = true;

            $found[] = [
                'url'      => $normalised,
                'anchor'   => substr(trim($anchor->textContent), 0, 512),
                'nofollow' => $nofollow,
            ];
        }

        // NOTE: We intentionally do NOT filter out already-known URLs here.
        // persistLinks() must receive all links so it can increment
        // InboundLinksCount on existing AuditPage rows (e.g. sitemap-seeded
        // pages that are also linked from other pages). Filtering only for
        // new-page creation happens inside persistLinks().
        return $found;
    }

    // ── 2. checkResponse ─────────────────────────────────────────────────────

    /**
     * HTTP HEAD (fallback GET) against $url.
     *
     * @return array{status: int, redirect: string|null, latency: float}
     */
    public function checkResponse(string $url): array
    {
        $result = ['status' => 0, 'redirect' => null, 'latency' => 0.0];

        $options = [
            'allow_redirects' => false,
            'connect_timeout' => (int) self::config()->get('connect_timeout'),
            'timeout'         => (int) self::config()->get('request_timeout'),
            'headers'         => ['User-Agent' => self::config()->get('user_agent')],
            'on_stats'        => function (TransferStats $stats) use (&$result): void {
                $result['latency'] = $stats->getTransferTime() * 1000;
            },
            'http_errors' => false,
        ];

        try {
            $response = $this->httpClient->head($url, $options);
            $status   = $response->getStatusCode();

            // Servers that refuse HEAD
            if ($status === 405) {
                $response = $this->httpClient->get($url, array_merge($options, ['stream' => true]));
                $status   = $response->getStatusCode();
            }

            $result['status'] = $status;

            if ($status >= 301 && $status <= 308) {
                $location = $response->getHeaderLine('Location');
                if ($location) {
                    $result['redirect'] = $location;
                }
            }

        } catch (TooManyRedirectsException $e) {
            $result['status'] = 310;
            $this->logger->warning("SiteSpider redirect loop: {$url}");
        } catch (ConnectException $e) {
            $result['status'] = 0;
            $this->logger->warning("SiteSpider connect error: {$url} — {$e->getMessage()}");
        } catch (RequestException $e) {
            $result['status'] = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $this->logger->warning("SiteSpider request error: {$url} — {$e->getMessage()}");
        }

        return $result;
    }

    // ── 3. extractMetadata ───────────────────────────────────────────────────

    /**
     * @return array{
     *   title: string, description: string, h1: string, keywords: string, alt_texts: string[],
     *   robots_noindex: bool, robots_nofollow: bool, canonical: string,
     *   title_length: int, meta_description_length: int,
     *   h1_count: int, h2_count: int, word_count: int, images_without_alt: int,
     *   og_title: string, og_description: string, og_image: string
     * }
     */
    public function extractMetadata(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // ── Title ─────────────────────────────────────────────────────────────
        $titleNode = $xpath->query('//title')->item(0);
        $title     = $titleNode ? trim($titleNode->textContent) : '';

        // ── Meta description ──────────────────────────────────────────────────
        $descNode = $xpath->query('//meta[@name="description"]/@content')->item(0);
        $desc     = $descNode ? trim($descNode->nodeValue) : '';

        // ── Meta robots ───────────────────────────────────────────────────────
        $robotsNode    = $xpath->query('//meta[@name="robots"]/@content')->item(0);
        $robotsContent = $robotsNode ? strtolower($robotsNode->nodeValue) : '';
        $noindex       = str_contains($robotsContent, 'noindex');
        $nofollow      = str_contains($robotsContent, 'nofollow');

        // ── Canonical ─────────────────────────────────────────────────────────
        $canonNode = $xpath->query('//link[@rel="canonical"]/@href')->item(0);
        $canonical = $canonNode ? trim($canonNode->nodeValue) : '';

        // ── H1 / H2 ───────────────────────────────────────────────────────────
        $h1Nodes = $xpath->query('//h1');
        $h1Count = $h1Nodes ? $h1Nodes->length : 0;
        $h1      = ($h1Nodes && $h1Nodes->length > 0) ? trim($h1Nodes->item(0)->textContent) : '';

        $h2Nodes = $xpath->query('//h2');
        $h2Count = $h2Nodes ? $h2Nodes->length : 0;

        // ── Body text / keywords / word count ─────────────────────────────────
        $keywords  = '';
        $wordCount = 0;
        $body      = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $text      = preg_replace('/\s+/', ' ', $body->textContent);
            $words     = str_word_count(strtolower((string) $text), 1);
            $wordCount = count($words);
            $stopwords = ['the','a','an','is','in','of','to','and','for','it','on',
                          'at','by','or','be','as','are','this','that','with','from',
                          'was','has','not','but','we','our','you','your'];
            $words     = array_diff($words, $stopwords);
            $freq      = array_count_values($words);
            arsort($freq);
            $keywords  = implode(', ', array_slice(array_keys($freq), 0, 10));
        }

        // ── Images ────────────────────────────────────────────────────────────
        $alts             = [];
        $imagesWithoutAlt = 0;
        foreach ($xpath->query('//img') as $img) {
            /** @var \DOMElement $img */
            $alt = trim($img->getAttribute('alt'));
            if ($alt) {
                $alts[] = $alt;
            } else {
                $imagesWithoutAlt++;
            }
        }

        // ── Open Graph ────────────────────────────────────────────────────────
        $ogTitleNode = $xpath->query('//meta[@property="og:title"]/@content')->item(0);
        $ogTitle     = $ogTitleNode ? trim($ogTitleNode->nodeValue) : '';

        $ogDescNode = $xpath->query('//meta[@property="og:description"]/@content')->item(0);
        $ogDesc     = $ogDescNode ? trim($ogDescNode->nodeValue) : '';

        $ogImageNode = $xpath->query('//meta[@property="og:image"]/@content')->item(0);
        $ogImage     = $ogImageNode ? trim($ogImageNode->nodeValue) : '';

        return [
            'title'                    => substr($title, 0, 512),
            'description'              => $desc,
            'h1'                       => substr($h1, 0, 512),
            'keywords'                 => $keywords,
            'alt_texts'                => $alts,
            'robots_noindex'           => $noindex,
            'robots_nofollow'          => $nofollow,
            'canonical'                => substr($canonical, 0, 2048),
            'title_length'             => strlen($title),
            'meta_description_length'  => strlen($desc),
            'h1_count'                 => $h1Count,
            'h2_count'                 => $h2Count,
            'word_count'               => $wordCount,
            'images_without_alt'       => $imagesWithoutAlt,
            'og_title'                 => substr($ogTitle, 0, 512),
            'og_description'           => $ogDesc,
            'og_image'                 => substr($ogImage, 0, 2048),
        ];
    }

    // ── 4. persistLinks ──────────────────────────────────────────────────────

    /**
     * Write PageLink edges for $sourcePage and update Inbound/Outbound counters.
     * Idempotent — safe to call multiple times for the same page.
     *
     * @param array<int, array{url: string, anchor: string, nofollow: bool}> $links
     */
    public function persistLinks(AuditPage $sourcePage, array $links, int $sessionId): void
    {
        $outboundCount = 0;

        foreach ($links as $linkData) {
            // Look up existing AuditPage (may have been seeded from sitemap)
            $targetPage = AuditPage::get()
                ->filter(['URL' => $linkData['url'], 'AuditSessionID' => $sessionId])
                ->first();

            // Only create a new row if this URL was never seen in this session.
            // Pages already in the queue (e.g. from sitemap) are reused so
            // their InboundLinksCount is correctly incremented below.
            if (!$targetPage) {
                $targetPage                  = AuditPage::create();
                $targetPage->URL             = $linkData['url'];
                $targetPage->AuditSessionID  = $sessionId;
                $targetPage->IsCrawled       = false;
                $targetPage->IsFromSitemap   = false;
                $targetPage->write();
            }

            // Duplicate-safe edge insert — avoids double-counting when the
            // same page is crawled more than once (should not happen, but
            // defensive against retries / manual re-runs).
            $exists = PageLink::get()
                ->filter(['SourcePageID' => $sourcePage->ID, 'TargetPageID' => $targetPage->ID])
                ->exists();

            if (!$exists) {
                $link               = PageLink::create();
                $link->SourcePageID = $sourcePage->ID;
                $link->TargetPageID = $targetPage->ID;
                $link->AnchorText   = $linkData['anchor'];
                $link->IsNoFollow   = $linkData['nofollow'];
                $link->write();

                // Increment inbound counter and recompute orphan flag.
                // This is the critical step for sitemap-seeded pages:
                // before this fix, they never reached here because
                // discoverLinks() had already filtered them out.
                $targetPage->InboundLinksCount = (int) $targetPage->InboundLinksCount + 1;
                $targetPage->syncOrphanFlag();
                $targetPage->write();
            }

            $outboundCount++;
        }

        $sourcePage->OutboundLinksCount = $outboundCount;
        // Don't call write() here — caller does it with all fields at once
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isBinaryAsset(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        return in_array($ext, [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'tif', 'avif', 'heic',
            // Documents / archives
            'pdf', 'zip', 'tar', 'gz', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            // Media
            'mp4', 'webm', 'ogg', 'mp3', 'wav', 'avi', 'mov', 'wmv',
            // Fonts / other assets
            'woff', 'woff2', 'ttf', 'eot', 'otf', 'css', 'js', 'map',
        ], true);
    }

    private function resolveUrl(
        string $href,
        string $baseScheme,
        string $baseHost,
        array  $baseParsed
    ): ?string {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return $baseScheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $baseScheme . '://' . $baseHost . $href;
        }

        // Treat plain relative links as root-relative to avoid nesting
        // under the current path (e.g. /o-nama/home).
        return $baseScheme . '://' . $baseHost . '/' . ltrim($href, '/');
    }

    private function normaliseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host']   ?? '');
        $path   = $parts['path'] ?? '/';
        $query  = isset($parts['query']) ? '?' . $parts['query'] : '';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
