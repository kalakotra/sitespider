<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Extensions;

use Kalakotra\SiteSpider\Models\AuditProject;
use Kalakotra\SiteSpider\Models\AuditUsage;
use Kalakotra\SiteSpider\Services\SeoLimit;
use SilverStripe\Core\Extension;

class MemberSeoLimitsExtension extends Extension
{
    /**
     * Return package limits. A null value means unlimited.
     * Applications can mutate $limits through updateSeoLimits().
     *
     * @return array<string, int|null>
     */
    public function seoLimits(): array
    {
        $limits = [
            SeoLimit::MAX_DOMAINS => null,
            SeoLimit::CRAWL_TASK => null,
        ];

        $this->owner->extend('updateSeoLimits', $limits);

        foreach ($limits as $feature => $limit) {
            if ($limit !== null) {
                $limits[$feature] = max(0, (int) $limit);
            }
        }

        return $limits;
    }

    public function hasSeoLimit(string $feature): bool
    {
        $limits = $this->seoLimits();
        return array_key_exists($feature, $limits) && $limits[$feature] !== null;
    }

    public function getSeoLimit(string $feature): ?int
    {
        $limits = $this->seoLimits();
        if (!array_key_exists($feature, $limits) || $limits[$feature] === null) {
            return null;
        }

        return (int) $limits[$feature];
    }

    public function seoUsage(string $feature): int
    {
        if ($feature === SeoLimit::MAX_DOMAINS) {
            return (int) AuditProject::get()->filter(['OwnerID' => $this->owner->ID])->count();
        }
        if ($feature === SeoLimit::CRAWL_TASK) {
            return (int) AuditUsage::get()->filter([
                'OwnerID' => $this->owner->ID,
                'Feature' => $feature,
                'MonthKey' => date('Y-m'),
            ])->sum('Quantity');
        }

        return 0;
    }

    public function canUseSeoLimit(string $feature, int $requested = 1): bool
    {
        $limits = $this->seoLimits();
        if (!array_key_exists($feature, $limits)) {
            return false;
        }
        $limit = $limits[$feature] === null ? null : (int) $limits[$feature];
        return $limit === null || ($this->seoUsage($feature) + max(0, $requested)) <= $limit;
    }
}