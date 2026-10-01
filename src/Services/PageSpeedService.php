<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Kalakotra\SiteSpider\Models\AuditPage;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * PageSpeedService — Google PageSpeed Insights API v5 integration.
 *
 * Fetches Lighthouse lab data (Performance score, FCP, LCP, TBT, CLS)
 * for both mobile and desktop strategies and writes to AuditPage fields.
 *
 * Note: Sets fields on the AuditPage object but does NOT call write().
 * The caller (AuditService::crawlOnePage) is responsible for persisting.
 *
 * Free API key: https://developers.google.com/speed/docs/insights/v5/get-started
 * Quota: 25,000 requests/day — each page = 2 PSI requests (mobile + desktop).
 *
 * YAML config (override in app/_config/sitespider.yml):
 *   Kalakotra\SiteSpider\Services\PageSpeedService:
 *     enabled: true
 *     api_key: 'AIzaSy...'
 */
class PageSpeedService
{
    use Injectable;
    use Configurable;

    private static bool   $enabled = false;
    private static string $api_key = '';
    private static string $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private static int    $timeout = 60;

    public function __construct(
        private readonly Client          $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) self::config()->get('enabled')
            && !empty((string) self::config()->get('api_key'));
    }

    /**
     * Run PSI analysis for both mobile and desktop strategies.
     * Sets PSI_* fields on $page but does NOT call $page->write().
     *
     * @return bool true if at least one strategy succeeded
     */
    public function analyse(AuditPage $page): bool
    {
        $apiKey  = (string) self::config()->get('api_key');
        $updated = false;

        foreach (['mobile', 'desktop'] as $strategy) {
            $data = $this->fetchPSI($page->URL, $strategy, $apiKey);
            if (!$data) {
                continue;
            }

            $score  = $this->extractScore($data);
            $audits = $data['lighthouseResult']['audits'] ?? [];

            if ($strategy === 'mobile') {
                $page->PSI_MobileScore = $score;
                // Lab metrics from mobile (most conservative = most meaningful for real users)
                $page->PSI_FCP       = $this->extractMs($audits, 'first-contentful-paint');
                $page->PSI_LCP       = $this->extractMs($audits, 'largest-contentful-paint');
                $page->PSI_TBT       = $this->extractMs($audits, 'total-blocking-time');
                $page->PSI_CLS       = $this->extractCLS($audits);
                $page->PSI_CheckedAt = date('Y-m-d H:i:s');
            } else {
                $page->PSI_DesktopScore = $score;
            }

            $updated = true;
        }

        if ($updated) {
            $this->logger->info(sprintf(
                'PSI: mobile=%d desktop=%d — %s',
                (int) $page->PSI_MobileScore,
                (int) $page->PSI_DesktopScore,
                $page->URL
            ));
        }

        return $updated;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fetchPSI(string $url, string $strategy, string $apiKey): ?array
    {
        try {
            $response = $this->httpClient->get((string) self::config()->get('api_url'), [
                'query' => [
                    'url'      => $url,
                    'key'      => $apiKey,
                    'strategy' => $strategy,
                    'category' => 'performance',
                ],
                'timeout'     => (int) self::config()->get('timeout'),
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning("PSI returned HTTP {$response->getStatusCode()} for {$url} ({$strategy})");
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? $data : null;

        } catch (RequestException $e) {
            $this->logger->warning("PSI request error [{$strategy}] {$url}: {$e->getMessage()}");
            return null;
        }
    }

    private function extractScore(array $data): int
    {
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        return $score !== null ? (int) round((float) $score * 100) : 0;
    }

    private function extractMs(array $audits, string $id): int
    {
        $value = $audits[$id]['numericValue'] ?? null;
        return $value !== null ? (int) round((float) $value) : 0;
    }

    private function extractCLS(array $audits): float
    {
        $value = $audits['cumulative-layout-shift']['numericValue'] ?? null;
        return $value !== null ? round((float) $value, 4) : 0.0;
    }
}
