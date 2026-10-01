<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;


use SilverStripe\Control\Director;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * AuditSession — one complete crawl run for a single website.
 *
 * The session is the authoritative unit of work:
 *  - SitemapUrl  : XML sitemap endpoint used to pre-populate the page queue.
 *                  If empty, the spider falls back to BFS from BaseURL.
 *  - Status      : pending → running → completed | failed
 *  - CurrentPage : tracks which AuditPage the cron will process next tick,
 *                  enabling atomic one-page-per-cron-run processing.
 *
 * Cron model:
 *   Each cron tick calls SiteAuditSpiderTask, which picks the oldest
 *   AuditSession with Status='running' and crawls exactly ONE pending
 *   AuditPage within it. This keeps memory usage flat and allows multiple
 *   sessions to interleave fairly.
 *
 * @property string $BaseURL
 * @property string $SitemapUrl
 * @property string $Status        pending|running|completed|failed
 * @property int    $TotalPages
 * @property int    $TotalBroken
 * @property int    $TotalOrphans
 * @property int    $CrawledPages
 * @property string $StartedAt
 * @property string $FinishedAt
 * @property int    $CurrentPageID  FK → AuditPage currently being processed
 *
 * @method HasManyList Pages()
 */
class AuditSession extends DataObject
{
    private static string $table_name = 'KSS_AuditSession';

    private static string $singular_name = 'Audit Session';
    private static string $plural_name   = 'Audit Sessions';

    private static array $db = [
        'BaseURL'     => 'Varchar(2048)',
        'SitemapUrl'  => 'Varchar(2048)',
        'Status'      => "Enum('pending,running,completed,failed', 'pending')",
        'TotalPages'  => 'Int',
        'TotalBroken' => 'Int',
        'TotalOrphans'=> 'Int',
        'CrawledPages'=> 'Int',
        'StartedAt'   => 'Datetime',
        'FinishedAt'  => 'Datetime',
        'ShareToken'  => 'Varchar(64)',

        // Unique token per session — used as tracker.js ?token= parameter
        'ApiToken' => 'Varchar(128)',
    ];

    private static array $has_one = [
        // Pointer to the page currently being processed this tick.
        // NULL when no tick is running or session is complete.
        'CurrentPage' => AuditPage::class,
    ];

    private static array $has_many = [
        'Pages' => AuditPage::class . '.AuditSession',
    ];

    private static array $cascade_deletes = ['Pages'];

    private static array $summary_fields = [
        'BaseURL'      => 'Base URL',
        'SitemapUrl'   => 'Sitemap',
        'Status'       => 'Status',
        'CrawledPages' => 'Crawled',
        'TotalPages'   => 'Total',
        'TotalBroken'  => '404s',
        'TotalOrphans' => 'Orphans',
        'ProgressNice' => 'Progress',
        'Duration'     => 'Duration',
        'StartedAt'    => 'Started',
    ];

    private static array $searchable_fields = [
        'BaseURL',
        'Status',
    ];

    private static string $default_sort = 'Created DESC';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->ShareToken) {
            $this->ShareToken = bin2hex(random_bytes(16));
        }

        if (!$this->ApiToken) {
            $this->ApiToken = bin2hex(random_bytes(24)); // 48-char hex token
        }
    }

    // ── Virtual getters ───────────────────────────────────────────────────────

    public function getShareURL(): string
    {
        return Director::absoluteBaseURL() . '/audit-report/' . $this->ShareToken;
    }

    // ── CMS Fields ────────────────────────────────────────────────────────────

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['CurrentPageID', 'Pages', 'ShareToken']);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('BaseURL', 'Base URL')
                ->setDescription('Root URL of the site to crawl (e.g. https://example.com)'),
            TextField::create('SitemapUrl', 'Sitemap URL')
                ->setDescription('XML sitemap URL (e.g. https://example.com/sitemap.xml). '
                    . 'Spider will pre-populate the page queue from this file. '
                    . 'Leave empty to use BFS link discovery only.'),
            DropdownField::create('Status', 'Status', [
                'pending'   => 'Pending',
                'running'   => 'Running',
                'completed' => 'Completed',
                'failed'    => 'Failed',
            ]),
        ]);

        $fields->addFieldsToTab('Root.Stats', [
            ReadonlyField::create('CrawledPages', 'Crawled Pages'),
            ReadonlyField::create('TotalPages', 'Total Pages Discovered'),
            ReadonlyField::create('TotalBroken', 'Broken Pages (404)'),
            ReadonlyField::create('TotalOrphans', 'Orphan Pages'),
            ReadonlyField::create('ProgressNice', 'Progress'),
            ReadonlyField::create('Duration', 'Duration'),
            ReadonlyField::create('StartedAt', 'Started At'),
            ReadonlyField::create('FinishedAt', 'Finished At'),
        ]);

        if ($this->isInDB()) {
            $shareUrl = htmlspecialchars($this->getShareURL(), ENT_QUOTES, 'UTF-8');
            $fields->addFieldsToTab('Root.Share', [
                \SilverStripe\Forms\LiteralField::create('ShareURLField', '
                    <div class="field readonly">
                        <label class="left">Client Report URL</label>
                        <div class="middleColumn">
                            <a href="' . $shareUrl . '" target="_blank" style="font-size:12px;word-break:break-all;font-family:monospace">'
                                . $shareUrl .
                            '</a>
                            <p class="description">Pošalji ovaj link mušteriji. Prijava nije potrebna.</p>
                        </div>
                    </div>
                '),
            ]);

            $fields->addFieldsToTab('Root.Pages', [
                $this->buildPagesGrid('All', $this->Pages()),
            ]);

            $broken = $this->Pages()->filter('HttpStatus', 404);
            if ($broken->count()) {
                $fields->addFieldsToTab('Root.Broken404', [
                    $this->buildPagesGrid('Broken404', $broken),
                ]);
            }

            $orphans = $this->Pages()->filter('IsOrphan', true);
            if ($orphans->count()) {
                $fields->addFieldsToTab('Root.Orphans', [
                    $this->buildPagesGrid('Orphans', $orphans),
                ]);
            }

            $aiWarnings = $this->Pages()->exclude('KeywordCannibalizationWarning', ['', null]);
            if ($aiWarnings->count()) {
                $fields->addFieldsToTab('Root.AIWarnings', [
                    $this->buildPagesGrid('AIWarnings', $aiWarnings),
                ]);
            }
        }

        return $fields;
    }

    // ── GridField builder ─────────────────────────────────────────────────────

    private function buildPagesGrid(string $name, mixed $list): GridField
    {
        $config = GridFieldConfig_RecordViewer::create();

        $config->removeComponentsByType(GridFieldFilterHeader::class);
        $config->removeComponentsByType(GridFieldPaginator::class);
        $config->addComponent(new GridFieldSortableHeader());
        $config->addComponent(new GridFieldPaginator(50));
        $config->addComponent(new GridFieldExportButton('buttons-before-left'));

        /** @var GridFieldDataColumns $cols */
        $cols = $config->getComponentByType(GridFieldDataColumns::class);
        if ($cols) {
            $cols->setDisplayFields([
                'URL'                  => 'URL',
                'StatusBadge'          => 'HTTP',
                'ResponseTimeMs'       => 'ms',
                'IsHttpsBadge'         => 'HTTPS',
                'TitleIssueBadge'      => 'Title',
                'MetaDescIssueBadge'   => 'Desc',
                'H1Badge'              => 'H1',
                'RobotsBadge'          => 'Robots',
                'IsFromSitemapNice'    => 'Source',
                'InboundLinksCount'    => '↗ In',
                'OutboundLinksCount'   => '↘ Out',
                'IsOrphanNice'         => 'Orphan',
                'CannibalizationBadge' => 'AI ⚡',
                'AiOnPageBadge'        => 'AI On-Page',
                'CrawledAt'            => 'Crawled',
            ]);
        }

        return GridField::create($name, '', $list, $config);
    }

    // ── Virtual getters ───────────────────────────────────────────────────────

    public function getDuration(): string
    {
        if (!$this->StartedAt) {
            return '—';
        }

        $end  = $this->FinishedAt ? strtotime($this->FinishedAt) : time();
        $secs = $end - strtotime($this->StartedAt);

        if ($secs < 60) {
            return "{$secs}s";
        }

        $mins = (int) floor($secs / 60);
        $rem  = $secs % 60;

        return $mins > 60
            ? floor($mins / 60) . 'h ' . ($mins % 60) . 'm'
            : "{$mins}m {$rem}s";
    }

    public function getProgressNice(): string
    {
        $total = (int) $this->TotalPages;
        $done  = (int) $this->CrawledPages;

        if ($total === 0) {
            return '—';
        }

        $pct = (int) round(($done / $total) * 100);

        return "{$done}/{$total} ({$pct}%)";
    }

    // ── Business helpers ──────────────────────────────────────────────────────

    /**
     * Fetch the next uncrawled AuditPage for this session, ordered by ID
     * (FIFO queue — sitemap-seeded pages inserted in sitemap order).
     */
    public function nextPendingPage(): ?AuditPage
    {
        /** @var AuditPage|null */
        return AuditPage::get()
            ->filter([
                'AuditSessionID' => $this->ID,
                'IsCrawled'      => false,
            ])
            ->sort('ID ASC')
            ->first();
    }

    /**
     * True when all discovered pages have been crawled.
     */
    public function isExhausted(): bool
    {
        return !AuditPage::get()
            ->filter(['AuditSessionID' => $this->ID, 'IsCrawled' => false])
            ->exists();
    }
}
