<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * AuditPage — one crawled (or queued) URL within an AuditSession.
 *
 * Lifecycle:
 *   1. Created with IsCrawled=false (queued) — either from sitemap XML parse
 *      or from discoverLinks() discovering a new internal href.
 *   2. SiteAuditSpiderTask picks it up on the next cron tick, sets
 *      IsCrawled=true and populates all metrics.
 *
 * Spider metrics:
 *   InboundLinksCount              — internal pages linking TO this page
 *   OutboundLinksCount             — links leaving FROM this page
 *   IsOrphan                       — true when InboundLinksCount === 0
 *   KeywordCannibalizationWarning  — AI-generated overlap warning
 *
 * @property string $URL
 * @property int    $HttpStatus
 * @property string $PageTitle
 * @property string $MetaDescription
 * @property string $H1
 * @property string $BodyKeywords
 * @property string $RedirectTarget
 * @property bool   $IsCrawled
 * @property string $CrawledAt
 * @property bool   $IsFromSitemap
 * @property int    $InboundLinksCount
 * @property int    $OutboundLinksCount
 * @property bool   $IsOrphan
 * @property string $KeywordCannibalizationWarning
 * @property float  $ResponseTimeMs
 * @property bool   $IsHttps
 * @property bool   $RobotsNoIndex
 * @property bool   $RobotsNoFollow
 * @property string $CanonicalUrl
 * @property int    $TitleLength
 * @property int    $MetaDescriptionLength
 * @property int    $H1Count
 * @property int    $H2Count
 * @property int    $WordCount
 * @property int    $ImagesWithoutAlt
 * @property string $OgTitle
 * @property string $OgDescription
 * @property string $OgImage
 * @property string $AiOnPageReport
 * @property int    $AuditSessionID
 *
 * @method ManyManyList LinksTo()
 * @method ManyManyList LinkedFrom()
 */
class AuditPage extends DataObject
{
    private static string $table_name = 'KSS_AuditPage';

    private static string $singular_name = 'Audit Page';
    private static string $plural_name   = 'Audit Pages';

    private static array $db = [
        // ── Core crawl data ──────────────────────────────────────────────────
        'URL'             => 'Varchar(2048)',
        'HttpStatus'      => 'Int',
        'PageTitle'       => 'Varchar(512)',
        'MetaDescription' => 'Text',
        'H1'              => 'Varchar(512)',
        'BodyKeywords'    => 'Text',          // top-10 keywords, comma-separated
        'RedirectTarget'  => 'Varchar(2048)', // populated for 3xx responses
        'IsCrawled'       => 'Boolean',
        'CrawledAt'       => 'Datetime',
        'IsFromSitemap'   => 'Boolean',       // true = URL came from sitemap.xml

        // ── Spider metrics ───────────────────────────────────────────────────
        'InboundLinksCount'             => 'Int',
        'OutboundLinksCount'            => 'Int',
        'IsOrphan'                      => 'Boolean',
        'KeywordCannibalizationWarning' => 'Text',

        // ── Technical SEO ────────────────────────────────────────────────────
        'ResponseTimeMs'  => 'Float',         // page load latency in milliseconds
        'IsHttps'         => 'Boolean',       // URL uses HTTPS scheme
        'RobotsNoIndex'   => 'Boolean',       // meta robots noindex present
        'RobotsNoFollow'  => 'Boolean',       // meta robots nofollow present
        'CanonicalUrl'    => 'Varchar(2048)', // <link rel="canonical"> href

        // ── On-Page SEO ──────────────────────────────────────────────────────
        'TitleLength'            => 'Int',    // char count of <title>
        'MetaDescriptionLength'  => 'Int',    // char count of meta description
        'H1Count'                => 'Int',    // number of <h1> tags (ideal: 1)
        'H2Count'                => 'Int',    // number of <h2> tags
        'WordCount'              => 'Int',    // visible body word count
        'ImagesWithoutAlt'       => 'Int',    // <img> tags missing alt attribute

        // ── Social / Open Graph ──────────────────────────────────────────────
        'OgTitle'       => 'Varchar(512)',
        'OgDescription' => 'Text',
        'OgImage'       => 'Varchar(2048)',

        // ── AI Analysis ──────────────────────────────────────────────────────
        'AiOnPageReport' => 'Text',           // comprehensive single-page SEO analysis
    ];

    private static array $has_one = [
        'AuditSession' => AuditSession::class,
    ];

    private static array $many_many = [
        'LinksTo' => [
            'through' => PageLink::class,
            'from'    => 'SourcePage',
            'to'      => 'TargetPage',
        ],
    ];

    private static array $belongs_many_many = [
        'LinkedFrom' => AuditPage::class . '.LinksTo',
    ];

    private static array $cascade_deletes = ['LinksTo', 'LinkedFrom'];

    private static array $summary_fields = [
        'URL'                      => 'URL',
        'StatusBadge'              => 'HTTP',
        'IsHttpsBadge'             => 'HTTPS',
        'ResponseTimeMs'           => 'ms',
        'TitleIssueBadge'          => 'Title',
        'MetaDescIssueBadge'       => 'Desc',
        'H1Badge'                  => 'H1',
        'RobotsBadge'              => 'Robots',
        'IsFromSitemapNice'        => 'Sitemap',
        'InboundLinksCount'        => '↗ In',
        'OutboundLinksCount'       => '↘ Out',
        'IsOrphanNice'             => 'Orphan?',
        'CannibalizationBadge'     => 'AI ⚡',
        'AiOnPageBadge'            => 'AI On-Page',
        'CrawledAt'                => 'Crawled',
    ];

    private static array $searchable_fields = [
        'URL',
        'HttpStatus',
        'IsOrphan',
        'IsFromSitemap',
        'PageTitle',
    ];

    private static string $default_sort = 'InboundLinksCount DESC, ID ASC';

    // ── CMS Fields ────────────────────────────────────────────────────────────

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['LinksTo', 'LinkedFrom', 'AuditSessionID']);

        $fields->addFieldsToTab('Root.TechnicalSEO', [
            ReadonlyField::create('IsHttpsBadge', 'HTTPS'),
            ReadonlyField::create('ResponseTimeMs', 'Response Time (ms)'),
            ReadonlyField::create('RobotsBadge', 'Robots Meta'),
            ReadonlyField::create('CanonicalUrl', 'Canonical URL'),
        ]);

        $fields->addFieldsToTab('Root.OnPageSEO', [
            ReadonlyField::create('TitleIssueBadge', 'Title Length Issue'),
            ReadonlyField::create('TitleLength', 'Title Length (chars)'),
            ReadonlyField::create('MetaDescIssueBadge', 'Meta Description Issue'),
            ReadonlyField::create('MetaDescriptionLength', 'Meta Description Length (chars)'),
            ReadonlyField::create('H1Badge', 'H1 Status'),
            ReadonlyField::create('H1Count', 'H1 Count'),
            ReadonlyField::create('H2Count', 'H2 Count'),
            ReadonlyField::create('WordCount', 'Word Count'),
            ReadonlyField::create('ImagesWithoutAlt', 'Images Without Alt'),
            TextareaField::create('AiOnPageReport', 'AI On-Page SEO Analysis')
                ->setRows(10)
                ->setReadonly(true),
        ]);

        $fields->addFieldsToTab('Root.OpenGraph', [
            ReadonlyField::create('OgTitle', 'OG Title'),
            ReadonlyField::create('OgDescription', 'OG Description'),
            ReadonlyField::create('OgImage', 'OG Image URL'),
        ]);

        $fields->addFieldsToTab('Root.SpiderMetrics', [
            ReadonlyField::create('InboundLinksCount', 'Inbound Links'),
            ReadonlyField::create('OutboundLinksCount', 'Outbound Links'),
            ReadonlyField::create('IsOrphanNice', 'Orphan Status'),
            ReadonlyField::create('IsFromSitemapNice', 'Sourced From Sitemap'),
            TextareaField::create('KeywordCannibalizationWarning', 'AI Cannibalization Warning')
                ->setRows(5)
                ->setReadonly(true),
        ]);

        $fields->addFieldsToTab('Root.CrawlData', [
            ReadonlyField::create('URL', 'URL'),
            ReadonlyField::create('StatusBadge', 'HTTP Status'),
            ReadonlyField::create('RedirectTarget', 'Redirect Target'),
            ReadonlyField::create('PageTitle', 'Page Title'),
            ReadonlyField::create('MetaDescription', 'Meta Description'),
            ReadonlyField::create('H1', 'H1 Tag'),
            TextareaField::create('BodyKeywords', 'Extracted Keywords')->setReadonly(true),
            ReadonlyField::create('CrawledAt', 'Crawled At'),
        ]);

        return $fields;
    }

    // ── Virtual getters ───────────────────────────────────────────────────────

    public function getStatusBadge(): string
    {
        return match (true) {
            $this->HttpStatus === 200                             => '✓ 200',
            $this->HttpStatus >= 301 && $this->HttpStatus <= 308 => "↪ {$this->HttpStatus}",
            $this->HttpStatus === 404                            => '✗ 404',
            $this->HttpStatus >= 500                            => "⚠ {$this->HttpStatus}",
            $this->HttpStatus === 0                             => '? Timeout',
            default                                             => (string) $this->HttpStatus,
        };
    }

    public function getIsOrphanNice(): string
    {
        return $this->IsOrphan ? '⚠ Orphan' : '✓';
    }

    public function getIsFromSitemapNice(): string
    {
        return $this->IsFromSitemap ? '✓ Sitemap' : 'Discovered';
    }

    public function getCannibalizationBadge(): string
    {
        return $this->KeywordCannibalizationWarning ? '⚡ Warning' : '—';
    }

    public function getIsHttpsBadge(): string
    {
        return $this->IsHttps ? '✓ HTTPS' : '✗ HTTP';
    }

    public function getTitleIssueBadge(): string
    {
        $len = (int) $this->TitleLength;
        if ($len === 0) {
            return '✗ Missing';
        }
        if ($len < 30) {
            return '⚠ Too short';
        }
        if ($len > 60) {
            return '⚠ Too long';
        }
        return '✓ OK';
    }

    public function getMetaDescIssueBadge(): string
    {
        $len = (int) $this->MetaDescriptionLength;
        if ($len === 0) {
            return '✗ Missing';
        }
        if ($len > 160) {
            return '⚠ Too long';
        }
        return '✓ OK';
    }

    public function getH1Badge(): string
    {
        $count = (int) $this->H1Count;
        if ($count === 0) {
            return '✗ Missing';
        }
        if ($count > 1) {
            return "⚠ Multiple ({$count})";
        }
        return '✓ OK';
    }

    public function getRobotsBadge(): string
    {
        $parts = [];
        if ($this->RobotsNoIndex) {
            $parts[] = 'noindex';
        }
        if ($this->RobotsNoFollow) {
            $parts[] = 'nofollow';
        }
        return $parts ? ('⚠ ' . implode(', ', $parts)) : '✓ OK';
    }

    public function getAiOnPageBadge(): string
    {
        if ($this->AiOnPageReport) {
            return '✓ Analysed';
        }
        if (!$this->IsCrawled) {
            return '— Pending';
        }
        if ($this->HttpStatus !== 200) {
            return '— N/A';
        }
        return '⚠ AI Failed';
    }

    // ── Report helpers ────────────────────────────────────────────────────────

    /**
     * Space-separated flag list used by the client-report JS filter.
     * Possible values: broken, redirect, orphan, noindex, ai
     */
    public function getReportFlags(): string
    {
        $flags = [];
        if ($this->HttpStatus === 404) {
            $flags[] = 'broken';
        } elseif ($this->HttpStatus >= 301 && $this->HttpStatus <= 308) {
            $flags[] = 'redirect';
        }
        if ($this->IsOrphan) {
            $flags[] = 'orphan';
        }
        if ($this->RobotsNoIndex) {
            $flags[] = 'noindex';
        }
        if ($this->KeywordCannibalizationWarning) {
            $flags[] = 'ai';
        }
        return implode(' ', $flags);
    }

    // ── Business helpers ──────────────────────────────────────────────────────

    /**
     * Recompute IsOrphan from InboundLinksCount.
     * Always call this before write() when InboundLinksCount changes.
     */
    public function syncOrphanFlag(): void
    {
        $this->IsOrphan = ((int) $this->InboundLinksCount === 0);
    }

    public function isBroken(): bool
    {
        return $this->HttpStatus === 404;
    }

    public function isRedirect(): bool
    {
        return $this->HttpStatus >= 301 && $this->HttpStatus <= 308;
    }
}
