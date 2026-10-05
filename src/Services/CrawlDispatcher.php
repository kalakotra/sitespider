<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use Kalakotra\SiteSpider\Jobs\SiteAuditCrawlJob;
use Kalakotra\SiteSpider\Models\AuditProject;
use Kalakotra\SiteSpider\Models\AuditSession;

class CrawlDispatcher
{
    /** @return array{projects: int, sessions: int} */
    public function enqueueReadyCrawls(bool $noAI = false): array
    {
        $this->resumeQuotaSessions();
        $projects = $this->queueDueProjects();
        $sessions = 0;

        foreach (AuditSession::get()
            ->filter(['Status' => ['pending', 'running']])
            ->sort('ID ASC') as $session
        ) {
            SiteAuditCrawlJob::queueSession((int) $session->ID, $noAI);
            $sessions++;
        }

        return ['projects' => $projects, 'sessions' => $sessions];
    }

    private function resumeQuotaSessions(): void
    {
        foreach (AuditSession::get()->filter([
            'Status' => 'quota_exceeded',
            'QuotaResetAt:LessThanOrEqual' => date('Y-m-d H:i:s'),
        ]) as $session) {
            $session->Status = 'running';
            $session->QuotaResetAt = null;
            $session->write();
        }
    }

    private function queueDueProjects(): int
    {
        $queued = 0;
        foreach (AuditProject::get()->filter(['Enabled' => true])->sort('ID ASC') as $project) {
            if ($project->NextCrawlAt && strtotime((string) $project->NextCrawlAt) > time()) {
                continue;
            }

            $activeSession = AuditSession::get()->filter([
                'AuditProjectID' => $project->ID,
                'Status' => ['pending', 'running', 'quota_exceeded'],
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
            SiteAuditCrawlJob::queueSession((int) $session->ID);
            $queued++;
        }

        return $queued;
    }
}