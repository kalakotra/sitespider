<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use Kalakotra\SiteSpider\Models\AuditProject;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\HasAuditProjectAccess;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;

/** A durable SEO issue tracked across sessions for one monitored project. */
class AuditFinding extends DataObject
{
    use HasAuditProjectAccess;

    private static string $table_name = 'KSS_AuditFinding';
    private static string $singular_name = 'SEO Finding';
    private static string $plural_name = 'SEO Findings';

    private static array $db = [
        'URL' => 'Varchar(2048)',
        'Type' => 'Varchar(64)',
        'Description' => 'Text',
        'Priority' => "Enum('high,medium,low', 'medium')",
        'Status' => "Enum('open,resolved,ignored', 'open')",
        'FirstSeenAt' => 'Datetime',
        'LastSeenAt' => 'Datetime',
        'ResolvedAt' => 'Datetime',
        'Occurrences' => 'Int',
    ];

    private static array $has_one = [
        'AuditProject' => AuditProject::class,
        'FirstSession' => AuditSession::class,
        'LastSession' => AuditSession::class,
    ];

    private static array $indexes = [
        'AuditProjectID' => true,
        'Status' => true,
        'Type' => true,
    ];

    private static array $summary_fields = [
        'URL' => 'URL',
        'Type' => 'Issue',
        'Priority' => 'Priority',
        'Status' => 'Status',
        'Occurrences' => 'Seen',
        'FirstSeenAt' => 'First seen',
        'LastSeenAt' => 'Last seen',
        'ResolvedAt' => 'Resolved',
    ];

    private static array $searchable_fields = ['URL', 'Type', 'Status', 'Priority'];

    private static string $default_sort = "FIELD(Status,'open','ignored','resolved'), FIELD(Priority,'high','medium','low'), LastSeenAt DESC";

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            'AuditProjectID', 'FirstSessionID', 'LastSessionID', 'URL', 'Type',
            'Description', 'Priority', 'Status', 'FirstSeenAt', 'LastSeenAt',
            'ResolvedAt', 'Occurrences',
        ]);
        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('URL', 'URL'),
            ReadonlyField::create('Type', 'Issue'),
            ReadonlyField::create('Description', 'Description'),
            ReadonlyField::create('Priority', 'Priority'),
            ReadonlyField::create('FirstSeenAt', 'First seen'),
            ReadonlyField::create('LastSeenAt', 'Last seen'),
            ReadonlyField::create('ResolvedAt', 'Resolved'),
            ReadonlyField::create('Occurrences', 'Occurrences'),
            DropdownField::create('Status', 'Status', [
                'open' => 'Open',
                'ignored' => 'Ignored',
            ]),
        ]);
        return $fields;
    }
}