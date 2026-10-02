<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Tasks;

use Kalakotra\SiteSpider\Jobs\SiteAuditCrawlJob;
use Kalakotra\SiteSpider\Models\AuditProject;
use Kalakotra\SiteSpider\Models\AuditSession;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * SiteAuditSpiderTask — enqueue BuildTask for Kalakotra SiteSpider.
 *
 * ── Cron model ────────────────────────────────────────────────────────────────
 *
 * This task queues an active crawl session. ProcessJobQueueTask executes the
 * queued work one page at a time and schedules delayed continuations.
 *
 * ── Crontab (every minute): ───────────────────────────────────────────────────
 *   * * * * /path/to/project/vendor/bin/sake dev/tasks/ProcessJobQueueTask --queue=queued
 *
 * ── Manual usage: ─────────────────────────────────────────────────────────────
 *
 *   # Enqueue the next active session:
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask
 *
 *   # Create a new session and start crawling:
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask new=1 url=https://example.com sitemap=https://example.com/sitemap.xml
 *
 *   # Disable AI for this run:
 *   vendor/bin/sake dev/tasks/SiteAuditSpiderTask noai=1
 */
class SiteAuditSpiderTask extends BuildTask
{
    private static string $segment = 'SiteAuditSpiderTask';

    protected string $title = 'Kalakotra SiteSpider - Enqueue Crawl';

    protected static string $description =
        'Queues active SiteSpider sessions for background processing. '
        . 'Each queued job processes one page and schedules the next step.';

    private PolyOutput $output;

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->output = $output;

        $noAI = (bool) ($this->getInputValue($input, 'noai') ?? false);
        $newRun = (bool) ($this->getInputValue($input, 'new') ?? false);
        // ── Optional: create a new session from CLI params ────────────────────
        if ($newRun) {
            $injector = Injector::inst();
            $logger = $injector->get(LoggerInterface::class);
            $httpClient = $injector->get(\GuzzleHttp\Client::class);
            $spider = $injector->createWithArgs(\Kalakotra\SiteSpider\Services\SpiderService::class, [
                'httpClient' => $httpClient,
                'logger' => $logger,
            ]);
            $aiGateway = $injector->createWithArgs(\Kalakotra\AIGateway\Services\AIGatewayService::class, [
                'registry' => $injector->get(\Kalakotra\AIGateway\Services\AIProviderRegistry::class),
                'logger' => $logger,
            ]);
            $pageSpeed = $injector->createWithArgs(\Kalakotra\SiteSpider\Services\PageSpeedService::class, [
                'httpClient' => $httpClient,
                'logger' => $logger,
            ]);
            $service = $injector->createWithArgs(\Kalakotra\SiteSpider\Services\AuditService::class, [
                'spider' => $spider,
                'aiGateway' => $aiGateway,
                'pageSpeed' => $pageSpeed,
                'httpClient' => $httpClient,
                'logger' => $logger,
            ]);

            $baseUrl = (string) ($this->getInputValue($input, 'url') ?? '');
            $sitemapUrl = (string) ($this->getInputValue($input, 'sitemap') ?? '');

            if (!$baseUrl) {
                $baseUrl = $this->resolveBaseUrl();
            }

            if (!$baseUrl) {
                $this->log('[ERROR] Cannot create session: no url= parameter and SiteConfig has no BaseURL.');
                return Command::FAILURE;
            }

            $session = $service->createSession($baseUrl, $sitemapUrl, $noAI);
            $this->log("[SESSION] Created #{$session->ID} for {$baseUrl}"
                . ($sitemapUrl ? " | Sitemap: {$sitemapUrl}" : ' | BFS mode (no sitemap)'));
        }

        $this->queueDueProjects();

        // ── Queue active sessions; duplicate session jobs are ignored ─────────
        $sessions = AuditSession::get()
            ->filter(['Status' => ['pending', 'running']])
            ->sort('ID ASC');
        $queuedCount = 0;

        foreach ($sessions as $session) {
            $jobId = SiteAuditCrawlJob::queueSession((int) $session->ID, $noAI);
            $this->log("[QUEUED] Session #{$session->ID}; job #{$jobId} processes one page per run.");
            $queuedCount++;
        }

        if (!$queuedCount) {
            $this->log('No active sessions to enqueue.');
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    private function queueDueProjects(): void
    {
        foreach (AuditProject::get()->filter(['Enabled' => true])->sort('ID ASC') as $project) {
            if ($project->NextCrawlAt && strtotime((string) $project->NextCrawlAt) > time()) {
                continue;
            }

            $activeSession = AuditSession::get()->filter([
                'AuditProjectID' => $project->ID,
                'Status' => ['pending', 'running'],
            ])->first();
            if ($activeSession) {
                continue;
            }

            $session = AuditSession::create();
            $session->AuditProjectID = $project->ID;
            $session->BaseURL = $project->BaseURL;
            $session->SitemapUrl = $project->SitemapUrl;
            $session->Status = 'pending';
            $session->write();

            $jobId = SiteAuditCrawlJob::queueSession((int) $session->ID);
            $this->log("[SCHEDULED] Project #{$project->ID}; session #{$session->ID}, job #{$jobId}.");
        }
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
