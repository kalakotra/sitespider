<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Tasks;

use Kalakotra\SiteSpider\Services\AuditService;
use Kalakotra\SiteSpider\Services\SpiderService;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\AIGateway\Services\AIProviderRegistry;
use Kalakotra\SiteSpider\Services\PageSpeedService;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * SiteAuditSpiderTask — cron BuildTask for Kalakotra SiteSpider.
 *
 * ── Cron model ────────────────────────────────────────────────────────────────
 *
 * This task processes exactly ONE AuditPage per invocation. The cron scheduler
 * (e.g. every minute) calls it repeatedly until all sessions are complete.
 * This keeps PHP memory usage flat, avoids timeout issues, and allows multiple
 * AuditSessions to make progress fairly.
 *
 * ── Crontab (every minute): ───────────────────────────────────────────────────
 *   * * * * * /path/to/project/vendor/bin/sake dev/tasks/SiteAuditSpiderTask >> /var/log/sitespider.log 2>&1
 *
 * ── Manual usage: ─────────────────────────────────────────────────────────────
 *
 *   # Run one tick (process next pending page across all sessions):
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask
 *
 *   # Create a new session and start crawling:
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask new=1 url=https://example.com sitemap=https://example.com/sitemap.xml
 *
 *   # Run N ticks in sequence (for manual/dev use):
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask ticks=50
 *
 *   # Disable AI for this run:
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask noai=1
 */
class SiteAuditSpiderTask extends BuildTask
{
    private static string $segment = 'SiteAuditSpiderTask';

    protected string $title = 'Kalakotra SiteSpider - Crawl Tick';

    protected static string $description =
        'Processes ONE pending AuditPage per invocation. '
        . 'Run via cron every minute. '
        . 'Supports multiple concurrent AuditSessions, each with optional sitemap seeding.';

    private PolyOutput $output;

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->output = $output;

        $noAI = (bool) ($this->getInputValue($input, 'noai') ?? false);
        $newRun = (bool) ($this->getInputValue($input, 'new') ?? false);
        $ticks = max(1, (int) ($this->getInputValue($input, 'ticks') ?? 1));

        // ── Optional: temporarily disable AI for this run ─────────────────────
        if ($noAI) {
            Config::modify()->set(AuditService::class, 'ai_cannibalization_enabled', false);
            Config::modify()->set(AuditService::class, 'ai_onpage_enabled', false);
            $this->log('[CONFIG] AI DISABLED for this run.');
        }

        /** @var LoggerInterface $logger */
        $logger = Injector::inst()->get(LoggerInterface::class);

        /** @var Client $httpClient */
        $httpClient = Injector::inst()->get(Client::class);

        /** @var SpiderService $spiderService */
        $spiderService = Injector::inst()->createWithArgs(SpiderService::class, [
            'httpClient' => $httpClient,
            'logger' => $logger,
        ]);

        /** @var AIGatewayService $aiGateway */
        $aiGateway = Injector::inst()->createWithArgs(AIGatewayService::class, [
            'registry' => Injector::inst()->get(AIProviderRegistry::class),
            'logger' => $logger,
        ]);

        /** @var PageSpeedService $pageSpeedService */
        $pageSpeedService = Injector::inst()->createWithArgs(PageSpeedService::class, [
            'httpClient' => $httpClient,
            'logger'     => $logger,
        ]);

        /** @var AuditService $service */
        $service = Injector::inst()->createWithArgs(AuditService::class, [
            'spider'    => $spiderService,
            'aiGateway' => $aiGateway,
            'pageSpeed' => $pageSpeedService,
            'httpClient' => $httpClient,
            'logger'    => $logger,
        ]);

        // ── Optional: create a new session from CLI params ────────────────────
        if ($newRun) {
            $baseUrl = (string) ($this->getInputValue($input, 'url') ?? '');
            $sitemapUrl = (string) ($this->getInputValue($input, 'sitemap') ?? '');

            if (!$baseUrl) {
                $baseUrl = $this->resolveBaseUrl();
            }

            if (!$baseUrl) {
                $this->log('[ERROR] Cannot create session: no url= parameter and SiteConfig has no BaseURL.');
                return Command::FAILURE;
            }

            $session = $service->createSession($baseUrl, $sitemapUrl);
            $this->log("[SESSION] Created #{$session->ID} for {$baseUrl}"
                . ($sitemapUrl ? " | Sitemap: {$sitemapUrl}" : ' | BFS mode (no sitemap)'));
        }

        // ── Process tick(s) ───────────────────────────────────────────────────
        for ($i = 0; $i < $ticks; $i++) {
            $result = $service->processTick();
            $this->log($result);

            // If nothing left to do, stop early
            if (str_starts_with($result, 'No active sessions')) {
                break;
            }
        }

        return Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveBaseUrl(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        if (!empty($siteConfig->BaseURL)) {
            return rtrim((string) $siteConfig->BaseURL, '/');
        }

        return rtrim(Director::absoluteBaseURL(), '/');
    }

    private function log(string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->output->writeln("[{$ts}] {$message}");
    }

    private function getInputValue(InputInterface $input, string $name): ?string
    {
        if (isset($_REQUEST[$name])) {
            return (string) $_REQUEST[$name];
        }

        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            foreach ($_SERVER['argv'] as $arg) {
                if (str_starts_with((string) $arg, $name . '=')) {
                    return (string) substr((string) $arg, strlen($name) + 1);
                }
            }
        }

        $value = $input->getParameterOption([$name, '--' . $name], null, true);
        return $value === false ? null : ($value !== null ? (string) $value : null);
    }
}
