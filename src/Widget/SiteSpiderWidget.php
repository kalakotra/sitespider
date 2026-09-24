<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Widget;

use Kalakotra\Dashboard\Widgets\StatsWidget;
use Kalakotra\Dashboard\Widgets\WidgetWidth;
use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * SiteSpiderWidget
 *
 * Full-width dashboard widget for the kalakotra/sitespider module.
 *
 * Layout:
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  Last session header: URL · Status badge · Progress bar         │
 * ├──────────┬───────────────────────┬─────────────────────────────┤
 * │ Active   │ Avg Response Time     │ Sessions (7d)               │
 * │ Crawls   │ (ms, last session)    │                             │
 * └──────────┴───────────────────────┴─────────────────────────────┘
 *
 * Extends StatsWidget from kalakotra/silverstripe-dashboard.
 * Extra data powers the custom SiteSpiderWidget.ss template header.
 */
class SiteSpiderWidget extends StatsWidget
{
    use Configurable;

    /**
     * Cache lifetime in seconds. Crawl stats change frequently while
     * a session is running, so we keep this short.
     */
    private static int $cache_lifetime = 30;

    protected string    $identifier      = 'SiteSpider';
    protected string    $title           = '🕷️ Site Spider';
    protected int       $order           = 20;
    protected WidgetWidth $width         = WidgetWidth::Full;
    protected bool      $supportsRefresh = true;

    // ── StatsWidget contract ──────────────────────────────────────────────────

    /**
     * Returns the three KPI tiles displayed below the session header.
     *
     * Tiles:
     *  1. Active Crawls        — sessions currently in 'running' state
     *  2. Avg Response Time    — average ResponseTimeMs across the most recent
     *                            completed session's crawled pages (ms)
     *  3. Sessions (7 days)    — audit sessions started in the last 7 days
     */
    public function getStats(): array
    {
        // ── 1. Active crawls ─────────────────────────────────────────────────
        $activeCrawls = AuditSession::get()
            ->filter('Status', 'running')
            ->count();

        // ── 2. Average response time of the last completed/running session ───
        $lastSession = AuditSession::get()
            ->filter('Status', ['completed', 'running'])
            ->sort('Created DESC')
            ->first();

        $avgResponseMs  = 0;
        $avgColor       = 'green';
        $avgDelta       = 'no data';

        if ($lastSession) {
            $avgRaw = AuditPage::get()
                ->filter([
                    'AuditSessionID' => $lastSession->ID,
                    'IsCrawled'      => true,
                    'HttpStatus'     => [200, 301, 302],
                ])
                ->avg('ResponseTimeMs');

            $avgResponseMs = $avgRaw ? (int) round((float) $avgRaw) : 0;

            if ($avgResponseMs > 2000) {
                $avgColor = 'red';
                $avgDelta = 'very slow';
            } elseif ($avgResponseMs > 800) {
                $avgColor = 'orange';
                $avgDelta = 'needs improvement';
            } else {
                $avgDelta = 'good';
            }
        }

        // ── 3. Sessions started in the last 7 days ───────────────────────────
        $sessions7d = AuditSession::get()
            ->filter([
                'Created:GreaterThan' => date('Y-m-d H:i:s', strtotime('-7 days')),
            ])
            ->count();

        return [
            [
                'label' => 'Active Crawls',
                'value' => $activeCrawls,
                'icon'  => 'font-icon-search',
                'color' => $activeCrawls > 0 ? 'orange' : 'green',
                'delta' => $activeCrawls > 0 ? 'in progress' : 'idle',
                'trend' => $activeCrawls > 0 ? 'neutral' : 'up',
                'link'  => '/admin/site-spider',
            ],
            [
                'label' => 'Avg Response Time',
                'value' => $avgResponseMs . ' ms',
                'icon'  => 'font-icon-clock',
                'color' => $avgColor,
                'delta' => $avgDelta,
                'trend' => $avgResponseMs > 800 ? 'down' : 'up',
                'link'  => '/admin/site-spider',
            ],
            [
                'label' => 'Sessions (7d)',
                'value' => $sessions7d,
                'icon'  => 'font-icon-calendar',
                'color' => $sessions7d > 0 ? 'green' : 'neutral',
                'delta' => 'audit runs',
                'trend' => $sessions7d > 0 ? 'up' : 'neutral',
                'link'  => '/admin/site-spider',
            ],
        ];
    }

    /**
     * Extra data for the custom SiteSpiderWidget.ss template.
     *
     * Provides last-session header: URL, status badge, progress
     * percentage and counts for the progress bar rendering.
     */
    public function getExtraData(): array
    {
        /** @var AuditSession|null $last */
        $last = AuditSession::get()
            ->sort('Created DESC')
            ->first();

        if (!$last) {
            return [
                'HasLastSession'   => false,
                'LastSessionUrl'   => '',
                'LastStatus'       => '',
                'LastStatusColor'  => 'grey',
                'ProgressPct'      => 0,
                'CrawledPages'     => 0,
                'TotalPages'       => 0,
                'TotalBroken'      => 0,
                'TotalOrphans'     => 0,
                'LastDuration'     => '—',
                'LastSessionAdminLink' => '/admin/site-spider',
            ];
        }

        $total   = (int) $last->TotalPages;
        $crawled = (int) $last->CrawledPages;
        $pct     = $total > 0 ? (int) round(($crawled / $total) * 100) : 0;

        $statusColorMap = [
            'pending'   => 'grey',
            'running'   => 'orange',
            'completed' => 'green',
            'failed'    => 'red',
        ];

        return [
            'HasLastSession'      => true,
            'LastSessionUrl'      => $last->BaseURL,
            'LastStatus'          => ucfirst($last->Status),
            'LastStatusColor'     => $statusColorMap[$last->Status] ?? 'grey',
            'ProgressPct'         => $pct,
            'CrawledPages'        => $crawled,
            'TotalPages'          => $total,
            'TotalBroken'         => (int) $last->TotalBroken,
            'TotalOrphans'        => (int) $last->TotalOrphans,
            'LastDuration'        => $last->getDuration(),
            'LastSessionAdminLink' => '/admin/site-spider',
        ];
    }

    public function getCacheLifetime(): int
    {
        return (int) $this->config()->get('cache_lifetime');
    }

    public function canView(Member $member): bool
    {
        return Permission::checkMember($member, 'ADMIN')
            || Permission::checkMember($member, 'SITESPIDER_VIEW');
    }
}
