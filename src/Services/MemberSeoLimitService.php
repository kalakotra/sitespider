<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Services;

use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\AuditUsage;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;

class MemberSeoLimitService
{
    /** @return array{status: string, resetAt: ?string} */
    public function reserveCrawlPage(AuditSession $session, AuditPage $page): array
    {
        $project = $session->AuditProject();
        if (!$project || !$project->exists() || !(int) $project->OwnerID) {
            return ['status' => 'allowed', 'resetAt' => null];
        }

        $owner = Member::get()->byID((int) $project->OwnerID);
        if (!$owner || !$owner->exists()) {
            return ['status' => 'allowed', 'resetAt' => null];
        }

        $monthKey = date('Y-m');
        $existing = AuditUsage::get()->filter([
            'AuditSessionID' => $session->ID,
            'AuditPageID' => $page->ID,
            'Feature' => SeoLimit::CRAWL_TASK,
        ])->first();
        if ($existing) {
            return ['status' => 'allowed', 'resetAt' => null];
        }

        $limit = $owner->getSeoLimit(SeoLimit::CRAWL_TASK);
        $lockSuffix = substr(hash('sha256', $owner->ID . ':' . $monthKey), 0, 40);
        $lockName = 'sitespider_quota_' . $lockSuffix;
        if ((int) DB::query("SELECT GET_LOCK('{$lockName}', 5)")->value() !== 1) {
            return ['status' => 'busy', 'resetAt' => null];
        }

        try {
            $existing = AuditUsage::get()->filter([
                'AuditSessionID' => $session->ID,
                'AuditPageID' => $page->ID,
                'Feature' => SeoLimit::CRAWL_TASK,
            ])->first();
            if ($existing) {
                return ['status' => 'allowed', 'resetAt' => null];
            }

            $used = (int) AuditUsage::get()->filter([
                'OwnerID' => $owner->ID,
                'Feature' => SeoLimit::CRAWL_TASK,
                'MonthKey' => $monthKey,
            ])->sum('Quantity');

            if ($limit !== null && $used >= $limit) {
                $resetAt = date('Y-m-01 00:00:00', strtotime('first day of next month'));
                return ['status' => 'limit', 'resetAt' => $resetAt];
            }

            $usage = AuditUsage::create();
            $usage->OwnerID = $owner->ID;
            $usage->AuditProjectID = $project->ID;
            $usage->AuditSessionID = $session->ID;
            $usage->AuditPageID = $page->ID;
            $usage->Feature = SeoLimit::CRAWL_TASK;
            $usage->MonthKey = $monthKey;
            $usage->Quantity = 1;
            $usage->ConsumedAt = date('Y-m-d H:i:s');
            $usage->write();

            return ['status' => 'allowed', 'resetAt' => null];
        } finally {
            DB::query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }
}