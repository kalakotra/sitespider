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

    private function queueDueProjects(): int
    {
        $queued = 0;
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
            SiteAuditCrawlJob::queueSession((int) $session->ID);
            $queued++;
        }

        return $queued;
    }
}