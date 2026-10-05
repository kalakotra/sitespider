<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use Kalakotra\SiteSpider\Models\AuditFinding;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\HasAuditProjectAccess;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/** A monitored website with recurring crawl configuration. */
class AuditProject extends DataObject
{
    use HasAuditProjectAccess;

    private static string $table_name = 'KSS_AuditProject';
    private static string $singular_name = 'Audit Project';
    private static string $plural_name = 'Audit Projects';

    private static array $db = [
        'Name' => 'Varchar(128)',
        'BaseURL' => 'Varchar(2048)',
        'SitemapUrl' => 'Varchar(2048)',
        'Enabled' => 'Boolean',
        'CrawlFrequency' => "Enum('daily,weekly,monthly', 'weekly')",
        'NextCrawlAt' => 'Datetime',
        'LastCrawlAt' => 'Datetime',
        'AlertsEnabled' => 'Boolean',
        'NotificationEmail' => 'Varchar(254)',
    ];

    private static array $has_one = [
        'Owner' => Member::class,
    ];

    private static array $has_many = [
        'Sessions' => AuditSession::class . '.AuditProject',
        'Findings' => AuditFinding::class . '.AuditProject',
    ];

    private static array $summary_fields = [
        'Name' => 'Project',
        'BaseURL' => 'Website',
        'Enabled' => 'Monitoring',
        'CrawlFrequency' => 'Frequency',
        'NextCrawlAt' => 'Next crawl',
        'LastCrawlAt' => 'Last crawl',
    ];

    private static array $searchable_fields = ['Name', 'BaseURL', 'Enabled'];

    private static array $defaults = [
        'Enabled' => true,
        'CrawlFrequency' => 'weekly',
        'AlertsEnabled' => false,
    ];

    private static string $default_sort = 'Name ASC';

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $member = Security::getCurrentUser();
        if ($member && !Permission::checkMember($member, 'ADMIN')) {
            if ($this->isInDB()) {
                $storedOwnerId = (int) AuditProject::get()->byID((int) $this->ID)?->OwnerID;
                if ($storedOwnerId !== (int) $member->ID) {
                    throw new ValidationException('You cannot change a project owned by another member.');
                }
            }
            $this->OwnerID = (int) $member->ID;
        }
        if (!$this->OwnerID) {
            throw new ValidationException('A monitoring project must have an owner.');
        }
        if (!$this->isInDB() && $member && !Permission::checkMember($member, 'ADMIN')) {
            $owner = Member::get()->byID((int) $this->OwnerID);
            if ($owner && !$owner->canUseSeoLimit(\Kalakotra\SiteSpider\Services\SeoLimit::MAX_DOMAINS)) {
                throw new ValidationException('Your plan domain limit has been reached.');
            }
        }
        $this->BaseURL = rtrim(trim((string) $this->BaseURL), '/');
        if (!$this->CrawlFrequency) {
            $this->CrawlFrequency = 'weekly';
        }
        if (!$this->Name) {
            $this->Name = (string) (parse_url($this->BaseURL, PHP_URL_HOST) ?: $this->BaseURL);
        }
        if ($this->Enabled && !$this->NextCrawlAt) {
            $this->NextCrawlAt = date('Y-m-d H:i:s');
        }
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Sessions', 'Findings']);
        $fields->removeByName(['NextCrawlAt', 'LastCrawlAt', 'OwnerID']);
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Name', 'Project name'),
            TextField::create('BaseURL', 'Website URL'),
            TextField::create('SitemapUrl', 'Sitemap URL'),
            CheckboxField::create('Enabled', 'Enable recurring monitoring'),
            DropdownField::create('CrawlFrequency', 'Crawl frequency', [
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
            ]),
            ReadonlyField::create('NextCrawlAt', 'Next crawl'),
            ReadonlyField::create('LastCrawlAt', 'Last crawl'),
        ]);
        $fields->addFieldsToTab('Root.Alerts', [
            CheckboxField::create('AlertsEnabled', 'Email on new or recurring issues'),
            EmailField::create('NotificationEmail', 'Notification email'),
        ]);
        if (Permission::checkMember(Security::getCurrentUser(), 'ADMIN')) {
            $fields->addFieldToTab('Root.Main', DropdownField::create(
                'OwnerID',
                'Tenant owner',
                Member::get()->map('ID', 'Email')->toArray()
            )->setEmptyString('(select owner)'));
        } else {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create('Owner.Email', 'Tenant owner'));
        }
        return $fields;
    }

    public function canEdit($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        return Permission::checkMember($member, 'ADMIN') || $this->isAuditProjectOwner($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        $member ??= Security::getCurrentUser();
        return $member instanceof Member && $member->exists();
    }

    public function scheduleNextCrawl(?string $from = null): void
    {
        $base = $from ? strtotime($from) : time();
        $interval = match ($this->CrawlFrequency) {
            'daily' => '+1 day',
            'monthly' => '+1 month',
            default => '+1 week',
        };
        $this->LastCrawlAt = date('Y-m-d H:i:s', $base);
        $this->NextCrawlAt = $this->Enabled
            ? date('Y-m-d H:i:s', strtotime($interval, $base))
            : null;
        $this->write();
    }
}