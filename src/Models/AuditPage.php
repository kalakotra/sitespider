<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use Kalakotra\SiteSpider\Models\HasAuditProjectAccess;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Kalakotra\SiteSpider\Jobs\SiteAuditCrawlJob;

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
 * @property bool   $RobotsBlocked
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
 * @method HasManyList  BeaconLogs()
 * @method HasManyList  Tasks()
 */
class AuditPage extends DataObject
{
    use HasAuditProjectAccess;

    private static string $table_name = 'KSS_AuditPage';

    private static string $singular_name = 'Audit Page';
    private static string $plural_name   = 'Audit Pages';

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        if ($this->IsCrawled || !$this->AuditSessionID) {
            return;
        }

        $session = AuditSession::get()->byID((int) $this->AuditSessionID);
        if (!$session) {
            return;
        }

        if ($session->Status === 'completed') {
            $session->Status = 'running';
            $session->FinishedAt = null;
            $session->write();
        }

        if (in_array($session->Status, ['pending', 'running'], true)) {
            SiteAuditCrawlJob::queueSession((int) $session->ID);
        }
    }

    public function canEdit($member = null): bool
    {
        return Permission::checkMember($member ?? Security::getCurrentUser(), 'ADMIN');
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::checkMember($member ?? Security::getCurrentUser(), 'ADMIN');
    }

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
        'RobotsBlocked'   => 'Boolean',
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

        // ── Core Web Vitals (p75, from BeaconLog aggregation) ────────────────────
        'CWV_LCP'          => 'Int',            // Largest Contentful Paint ms
        'CWV_CLS'          => 'Decimal(6,4)',   // Cumulative Layout Shift
        'CWV_INP'          => 'Int',            // Interaction to Next Paint ms
        'CWV_LCP_Rating'   => "Enum('good,needs-improvement,poor', null)",
        'CWV_CLS_Rating'   => "Enum('good,needs-improvement,poor', null)",
        'CWV_INP_Rating'   => "Enum('good,needs-improvement,poor', null)",
        'CWV_SampleCount'  => 'Int',            // Real user samples aggregated

        // ── Heading structure ────────────────────────────────────────────────────
        'H3Count'           => 'Int',
        'H4Count'           => 'Int',
        'H5Count'           => 'Int',
        'H6Count'           => 'Int',
        'HeadingOrderIssue' => 'Boolean',       // true if heading levels skip (e.g. H1→H3)

        // ── Structured Data ──────────────────────────────────────────────────────
        'HasStructuredData'    => 'Boolean',
        'StructuredDataTypes'  => 'Varchar(512)', // comma-separated: "Article, BreadcrumbList"
        'StructuredDataErrors' => 'Text',

        // ── Additional Technical ──────────────────────────────────────────────────
        'HasViewportMeta'    => 'Boolean',      // <meta name="viewport"> present
        'HreflangCount'      => 'Int',          // number of hreflang alternate tags
        'ExternalLinksCount' => 'Int',          // outbound external link count

        // ── Task checklist ────────────────────────────────────────────────────────
        'NeedsRecrawl' => 'Boolean',            // true when actionable tasks are resolved and at least one is done
        'TasksTotal'   => 'Int',
        'TasksDone'    => 'Int',

        // ── Google PageSpeed Insights (Lighthouse lab data) ────────────────────────
        'PSI_MobileScore'  => 'Int',            // Lighthouse performance score 0-100
        'PSI_DesktopScore' => 'Int',
        'PSI_FCP'          => 'Int',            // First Contentful Paint (ms)
        'PSI_LCP'          => 'Int',            // Largest Contentful Paint (ms)
        'PSI_TBT'          => 'Int',            // Total Blocking Time (ms)
        'PSI_CLS'          => 'Decimal(6,4)',   // Cumulative Layout Shift
        'PSI_CheckedAt'    => 'Datetime',

        // ── Google Search Console ──────────────────────────────────────────────────
        'GSC_Impressions'  => 'Int',
        'GSC_Clicks'       => 'Int',
        'GSC_CTR'          => 'Decimal(5,2)',   // Click-through rate (%)
        'GSC_Position'     => 'Decimal(5,1)',   // Average search position
        'GSC_CheckedAt'    => 'Datetime',
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

    private static array $has_many = [
        'BeaconLogs' => BeaconLog::class . '.AuditPage',
        'Tasks'      => AuditTask::class . '.AuditPage',
    ];

    private static array $cascade_deletes = ['LinksTo', 'LinkedFrom', 'BeaconLogs', 'Tasks'];

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

        'CWV_LCP'         => 'LCP (ms)',
        'CWV_CLS'         => 'CLS',
        'CWV_INP'         => 'INP (ms)',
        'CWV_LCP_Rating'   => 'LCP ✓',
        'CWV_SampleCount'  => 'Samples',
        // PSI + Tasks
        'PSI_MobileScore'  => 'PSI Mobile',
        'PSI_DesktopScore' => 'PSI Desktop',
        'TaskProgressBadge'=> 'Tasks',
        'NeedsRecrawl'     => 'Recrawl?',
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
            ReadonlyField::create('RobotsBlockedBadge', 'robots.txt Access'),
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

        $fields->addFieldsToTab('Root.Structure', [
            ReadonlyField::create('H1Count', 'H1 Tags'),
            ReadonlyField::create('H2Count', 'H2 Tags'),
            ReadonlyField::create('H3Count', 'H3 Tags'),
            ReadonlyField::create('H4Count', 'H4 Tags'),
            ReadonlyField::create('H5Count', 'H5 Tags'),
            ReadonlyField::create('H6Count', 'H6 Tags'),
            ReadonlyField::create('HeadingOrderIssueBadge', 'Heading Order'),
            ReadonlyField::create('StructuredDataBadge', 'Structured Data'),
            ReadonlyField::create('StructuredDataTypes', 'Schema Types'),
            TextareaField::create('StructuredDataErrors', 'Structured Data Errors')
                ->setRows(3)->setReadonly(true),
            ReadonlyField::create('HasViewportMetaBadge', 'Viewport Meta'),
            ReadonlyField::create('HreflangCount', 'Hreflang Tags'),
            ReadonlyField::create('ExternalLinksCount', 'External Links'),
        ]);

        $fields->addFieldsToTab('Root.PageSpeed', [
            ReadonlyField::create('PSIMobileBadge', 'Mobile Score'),
            ReadonlyField::create('PSI_MobileScore', 'Mobile Score (raw)'),
            ReadonlyField::create('PSI_DesktopScore', 'Desktop Score'),
            ReadonlyField::create('PSI_FCP', 'FCP (ms)'),
            ReadonlyField::create('PSI_LCP', 'LCP (ms)'),
            ReadonlyField::create('PSI_TBT', 'Total Blocking Time (ms)'),
            ReadonlyField::create('PSI_CLS', 'CLS'),
            ReadonlyField::create('PSI_CheckedAt', 'Last Checked'),
        ]);

        $fields->addFieldsToTab('Root.SearchConsole', [
            ReadonlyField::create('GSC_Impressions', 'Impressions'),
            ReadonlyField::create('GSC_Clicks', 'Clicks'),
            ReadonlyField::create('GSC_CTR', 'CTR (%)'),
            ReadonlyField::create('GSC_Position', 'Avg. Position'),
            ReadonlyField::create('GSC_CheckedAt', 'Last Synced'),
        ]);

        if ($this->isInDB()) {
            $fields->addFieldsToTab('Root.Tasks', [
                ReadonlyField::create('TaskProgressBadge', 'Task Progress'),
                $this->buildTasksGrid(),
            ]);
        }

        return $fields;
    }

    // ── Virtual getters ───────────────────────────────────────────────────────

    public function getStatusBadge(): string
    {
        if ($this->RobotsBlocked) {
            return '⛔ robots.txt';
        }

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

    public function getRobotsBlockedBadge(): string
    {
        return $this->RobotsBlocked ? 'Blocked by robots.txt' : 'Allowed';
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

    // ── Add virtual getter for CMS display ───────────────────────────────────────

    public function getCWVBadge(): string
    {
        if (!$this->CWV_SampleCount) return '—';

        $ratings = [$this->CWV_LCP_Rating, $this->CWV_CLS_Rating, $this->CWV_INP_Rating];
        $ratings = array_filter($ratings);

        if (in_array('poor', $ratings))             return '✗ Poor';
        if (in_array('needs-improvement', $ratings)) return '~ Needs work';
        return '✓ Good';
    }

    // ── Task checklist helpers ─────────────────────────────────────────────────

    /**
     * Recompute TasksTotal, TasksDone and NeedsRecrawl from Tasks() relation.
     * Does NOT call write() — caller is responsible.
     */
    public function syncTaskCounters(): void
    {
        $tasks            = $this->Tasks();
        $total            = $tasks->count();
        $done             = $tasks->filter(['Status' => 'done'])->count();
        $ignored          = $tasks->filter(['Status' => 'ignored'])->count();
        $this->TasksTotal   = $total;
        $this->TasksDone    = $done;
        $this->NeedsRecrawl = ($total > 0 && $done > 0 && $done + $ignored === $total
            && ($this->IsCrawled || $this->NeedsRecrawl));
    }

    public function getTaskProgressBadge(): string
    {
        $total = (int) $this->TasksTotal;
        if (!$total) return '—';
        $pct = (int) round(((int) $this->TasksDone / $total) * 100);
        return "{$this->TasksDone}/{$total} ({$pct}%)";
    }

    // ── PageSpeed helpers ─────────────────────────────────────────────────────

    public function getPSIMobileBadge(): string
    {
        $s = (int) $this->PSI_MobileScore;
        if (!$s) return '—';
        return match (true) {
            $s >= 90 => "✓ {$s}",
            $s >= 50 => "~ {$s}",
            default  => "✗ {$s}",
        };
    }

    // ── Structure helpers ─────────────────────────────────────────────────────

    public function getStructuredDataBadge(): string
    {
        if (!$this->HasStructuredData) return '✗ None';
        $types = $this->StructuredDataTypes ?: 'Detected';
        return "✓ {$types}";
    }

    public function getHeadingOrderIssueBadge(): string
    {
        return $this->HeadingOrderIssue ? '⚠ Issues detected' : '✓ OK';
    }

    public function getHasViewportMetaBadge(): string
    {
        return $this->HasViewportMeta ? '✓ Present' : '✗ Missing';
    }

    // ── GridField builder ─────────────────────────────────────────────────────

    private function buildTasksGrid(): GridField
    {
        $config = GridFieldConfig_RecordViewer::create();
        $config->addComponent(new GridFieldSortableHeader());
        $config->addComponent(new GridFieldPaginator(50));

        /** @var GridFieldDataColumns $cols */
        $cols = $config->getComponentByType(GridFieldDataColumns::class);
        if ($cols) {
            $cols->setDisplayFields([
                'TypeLabel'     => 'Issue',
                'PriorityBadge' => 'Priority',
                'Description'   => 'Description',
                'Status'        => 'Status',
                'ResolvedAt'    => 'Resolved',
            ]);
        }

        return GridField::create('Tasks', 'Audit Tasks', $this->Tasks(), $config);
    }
}
