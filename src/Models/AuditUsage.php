<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/** Idempotent monthly quota ledger for billable crawl-page work. */
class AuditUsage extends DataObject
{
    private static string $table_name = 'KSS_AuditUsage';
    private static string $singular_name = 'SEO Usage Entry';
    private static string $plural_name = 'SEO Usage Entries';

    private static array $db = [
        'Feature' => 'Varchar(32)',
        'MonthKey' => 'Varchar(7)',
        'Quantity' => 'Int',
        'ConsumedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Owner' => Member::class,
        'AuditProject' => AuditProject::class,
        'AuditSession' => AuditSession::class,
        'AuditPage' => AuditPage::class,
    ];

    private static array $indexes = [
        'OwnerMonthFeature' => [
            'type' => 'index',
            'columns' => ['OwnerID', 'MonthKey', 'Feature'],
        ],
        'UniquePageCharge' => [
            'type' => 'unique',
            'columns' => ['AuditSessionID', 'AuditPageID', 'Feature'],
        ],
    ];

    public function canView($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}