<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Admin;

use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\PageLink;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\View\Requirements;

/**
 * SiteAuditAdmin — CMS section for Kalakotra SiteSpider.
 *
 * AuditSession is the primary entry point. Pages and links are accessed
 * through the session detail view (Root.Pages, Root.Broken404, etc.).
 *
 * AJAX endpoint:
 *   GET /admin/site-spider/mapdata?session=42  — D3 force graph JSON
 */
class SiteAuditAdmin extends ModelAdmin
{
    private static string $url_segment     = 'site-spider';
    private static string $menu_title      = 'Site Spider';
    private static string $menu_icon_class = 'font-icon-search';
    private static int    $menu_priority   = 50;

    private static array $managed_models = [
        AuditSession::class => ['title' => 'Crawl Sessions'],
    ];

    private static array $allowed_actions = [
        'mapdata',
    ];

    private static array $url_handlers = [
        'mapdata'                     => 'mapdata',
        '$ModelClass/SearchForm'      => 'SearchForm',
        '$ModelClass/$Action'         => 'handleAction',
        ''                            => 'index',
    ];

    // ── Init override ─────────────────────────────────────────────────────────

    /**
     * ModelAdmin::init() validates the ModelClass URL param and throws for any
     * value that isn't a managed model — including our 'mapdata' action segment.
     * Skip it for AJAX requests and call LeftAndMain::init() directly instead.
     */
    public function init(): void
    {
        if (str_starts_with($this->getRequest()->remaining(), 'mapdata')) {
            LeftAndMain::init();
            return;
        }
        parent::init();
    }

    // ── GridField customisation ───────────────────────────────────────────────

    public function getEditForm($id = null, $fields = null): Form
    {
        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === AuditSession::class) {
            $this->customiseSessionGrid($form);
        }

        // Requirements::css('kalakotra/sitespider: client/css/spider-dashboard.css');
        // Requirements::javascript('kalakotra/sitespider: client/js/spider-dashboard.js');

        return $form;
    }

    private function customiseSessionGrid(Form $form): void
    {
        $gridField = $form->Fields()->dataFieldByName(
            str_replace('\\', '-', AuditSession::class)
        );

        if (!$gridField instanceof GridField) {
            return;
        }

        /** @var GridFieldDataColumns $cols */
        $cols = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        if ($cols) {
            $cols->setDisplayFields([
                'BaseURL'      => 'Base URL',
                'SitemapUrl'   => 'Sitemap',
                'Status'       => 'Status',
                'ProgressNice' => 'Progress',
                'TotalBroken'  => '404s',
                'TotalOrphans' => 'Orphans',
                'Duration'     => 'Duration',
                'StartedAt'    => 'Started',
            ]);
        }
    }

    // ── AJAX: map data for D3 visualisation ───────────────────────────────────

    /**
     * GET /admin/site-spider/mapdata?session=42
     *
     * Returns JSON {nodes, edges} for the force-directed link graph.
     * Capped at 200 nodes for browser performance.
     */
    public function mapdata(): HTTPResponse
    {
        $sessionId = (int) $this->getRequest()->getVar('session');

        $pages = AuditPage::get()
            ->filter(['AuditSessionID' => $sessionId, 'IsCrawled' => true])
            ->sort('InboundLinksCount DESC')
            ->limit(200);

        $nodes = [];
        $edges = [];
        $idMap = [];

        foreach ($pages as $i => $page) {
            $idMap[$page->ID] = $i;
            $nodes[] = [
                'id'          => $i,
                'url'         => $page->URL,
                'title'       => $page->PageTitle ?: $page->URL,
                'inbound'     => (int) $page->InboundLinksCount,
                'outbound'    => (int) $page->OutboundLinksCount,
                'status'      => (int) $page->HttpStatus,
                'orphan'      => (bool) $page->IsOrphan,
                'sitemap'     => (bool) $page->IsFromSitemap,
                'warning'     => (bool) $page->KeywordCannibalizationWarning,
                // SEO metrics
                'https'       => (bool) $page->IsHttps,
                'ms'          => round((float) $page->ResponseTimeMs),
                'noindex'     => (bool) $page->RobotsNoIndex,
                'title_len'   => (int) $page->TitleLength,
                'desc_len'    => (int) $page->MetaDescriptionLength,
                'h1_count'    => (int) $page->H1Count,
                'words'       => (int) $page->WordCount,
                'img_no_alt'  => (int) $page->ImagesWithoutAlt,
                'has_og'      => !empty($page->OgTitle),
                'ai_onpage'   => (bool) $page->AiOnPageReport,
            ];
        }

        $links = PageLink::get()->filter(['SourcePage.AuditSessionID' => $sessionId]);

        foreach ($links as $link) {
            if (isset($idMap[$link->SourcePageID], $idMap[$link->TargetPageID])) {
                $edges[] = [
                    'source' => $idMap[$link->SourcePageID],
                    'target' => $idMap[$link->TargetPageID],
                ];
            }
        }

        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');
        $response->setBody(json_encode(['nodes' => $nodes, 'edges' => $edges], JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
