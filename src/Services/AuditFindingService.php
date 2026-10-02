<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use Kalakotra\SiteSpider\Models\AuditFinding;
use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditProject;
use Kalakotra\SiteSpider\Models\AuditSession;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\MailerInterface;

class AuditFindingService
{
    use Configurable;

    public function __construct(private readonly LoggerInterface $logger) {}

    /** @return array{new: int, resolved: int, regressed: int} */
    public function reconcile(AuditSession $session): array
    {
        $project = $session->AuditProject();
        if (!$project || !$project->exists()) {
            return ['new' => 0, 'resolved' => 0, 'regressed' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $new = 0;
        $regressed = 0;
        $present = [];

        foreach (AuditPage::get()->filter(['AuditSessionID' => $session->ID]) as $page) {
            foreach ($page->Tasks() as $task) {
                if ($task->Status === 'done') {
                    continue;
                }

                $key = $this->key((string) $page->URL, (string) $task->Type);
                $present[$key] = true;
                $finding = AuditFinding::get()->filter([
                    'AuditProjectID' => $project->ID,
                    'URL' => (string) $page->URL,
                    'Type' => (string) $task->Type,
                ])->first();

                if (!$finding) {
                    $finding = AuditFinding::create();
                    $finding->AuditProjectID = $project->ID;
                    $finding->URL = (string) $page->URL;
                    $finding->Type = (string) $task->Type;
                    $finding->FirstSeenAt = $now;
                    $finding->FirstSessionID = $session->ID;
                    $finding->Occurrences = 0;
                    $new++;
                } elseif ($finding->Status === 'resolved') {
                    $regressed++;
                }

                $finding->Description = (string) $task->Description;
                $finding->Priority = (string) $task->Priority;
                if ($task->Status === 'ignored') {
                    $finding->Status = 'ignored';
                } elseif ($finding->Status !== 'ignored') {
                    $finding->Status = 'open';
                }
                $finding->LastSeenAt = $now;
                $finding->LastSessionID = $session->ID;
                $finding->Occurrences = (int) $finding->Occurrences + 1;
                $finding->ResolvedAt = null;
                $finding->write();
            }
        }

        $rescannedUrls = [];
        foreach (AuditPage::get()->filter([
            'AuditSessionID' => $session->ID,
            'IsCrawled' => true,
            'RobotsBlocked' => false,
            'HttpStatus' => 200,
        ])->column('URL') as $url) {
            $rescannedUrls[(string) $url] = true;
        }

        $resolved = 0;
        if ($rescannedUrls) {
            foreach (AuditFinding::get()->filter([
                'AuditProjectID' => $project->ID,
                'Status' => 'open',
            ]) as $finding) {
                if (isset($rescannedUrls[(string) $finding->URL])
                    && !isset($present[$this->key((string) $finding->URL, (string) $finding->Type)])) {
                    $finding->Status = 'resolved';
                    $finding->ResolvedAt = $now;
                    $finding->write();
                    $resolved++;
                }
            }
        }

        $session->NewFindings = $new;
        $session->ResolvedFindings = $resolved;
        $session->RegressedFindings = $regressed;
        $session->write();

        if ($new + $regressed > 0) {
            $this->sendAlert($project, $session, $new, $regressed);
        }

        return ['new' => $new, 'resolved' => $resolved, 'regressed' => $regressed];
    }

    private function key(string $url, string $type): string
    {
        return strtolower(rtrim($url, '/')) . "\0" . $type;
    }

    private function sendAlert(AuditProject $project, AuditSession $session, int $new, int $regressed): void
    {
        $to = trim((string) $project->NotificationEmail);
        if (!$project->AlertsEnabled || !$to || !Email::is_valid_address($to)) {
            return;
        }

        try {
            $mail = Email::create()
                ->to($to)
                ->subject("SiteSpider alert: {$project->Name}")
                ->text(sprintf(
                    "Monitoring crawl completed for %s.\n\nNew findings: %d\nRegressed findings: %d\nResolved findings: %d\n\nReview crawl session #%d in SiteSpider.",
                    $project->BaseURL,
                    $new,
                    $regressed,
                    (int) $session->ResolvedFindings,
                    $session->ID,
                ));

            $from = Email::config()->get('admin_email');
            if (is_array($from)) {
                $from = array_key_first($from);
            }
            if ($from) {
                $mail->from((string) $from);
            }

            Injector::inst()->get(MailerInterface::class)->send($mail);
        } catch (\Throwable $e) {
            $this->logger->warning("SiteSpider notification failed for project #{$project->ID}: {$e->getMessage()}");
        }
    }
}