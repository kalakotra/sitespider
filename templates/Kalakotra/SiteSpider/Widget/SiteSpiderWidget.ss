<%--
    SiteSpiderWidget.ss
    Overrides the default StatsWidget template to prepend a "last session" header
    with URL, status badge, and a crawl progress bar.

    Variables injected via getExtraData():
        $HasLastSession        (bool)
        $LastSessionUrl        (string)
        $LastStatus            (string — e.g. 'Running', 'Completed')
        $LastStatusColor       (string — 'green' | 'orange' | 'red' | 'grey')
        $ProgressPct           (int 0-100)
        $CrawledPages          (int)
        $TotalPages            (int)
        $TotalBroken           (int)
        $TotalOrphans          (int)
        $LastDuration          (string)
        $LastSessionAdminLink  (string)
-%>

<div class="dashboard-widget dashboard-widget--full ss-spider-widget"
     data-widget="SiteSpider">

    <%-- ── Header ─────────────────────────────────────────────────────────── -%>
    <div class="dashboard-widget__header ss-spider-header">
        <div class="ss-spider-icon-wrap">
            <span class="ss-spider-icon">🕷️</span>
        </div>

        <div class="ss-spider-meta">
            <h3 class="dashboard-widget__title">Site Spider</h3>

            <% if $HasLastSession %>
                <p class="ss-spider-url">
                    <a href="$LastSessionAdminLink" class="ss-spider-url-link">$LastSessionUrl</a>
                    <span class="ss-badge ss-badge--$LastStatusColor">$LastStatus</span>
                </p>

                <%-- Progress bar -%>
                <div class="ss-progress-wrap">
                    <div class="ss-progress-bar">
                        <div class="ss-progress-fill" style="width: {$ProgressPct}%;"></div>
                    </div>
                    <span class="ss-progress-label">
                        $CrawledPages / $TotalPages pages &nbsp;·&nbsp;
                        <span class="ss-progress-pct">$ProgressPct%</span>
                        <% if $TotalBroken %>&nbsp;·&nbsp; <span class="ss-stat-bad">$TotalBroken broken</span><% end_if %>
                        <% if $TotalOrphans %>&nbsp;·&nbsp; <span class="ss-stat-warn">$TotalOrphans orphans</span><% end_if %>
                        <% if $LastDuration %>&nbsp;·&nbsp; ⏱ $LastDuration<% end_if %>
                    </span>
                </div>
            <% else %>
                <p class="ss-spider-empty">No audit sessions yet. <a href="$LastSessionAdminLink">Start your first crawl →</a></p>
            <% end_if %>
        </div>

        <div class="ss-spider-actions">
            <a href="$LastSessionAdminLink" class="ss-btn ss-btn-primary">Open Spider →</a>
            <% if $supportsRefresh %>
            <button class="dashboard-widget__refresh" data-refresh-widget="$Identifier" title="Refresh">↻</button>
            <% end_if %>
        </div>
    </div>

    <%-- ── KPI Stat Tiles ──────────────────────────────────────────────────── -%>
    <div class="dashboard-stats ss-kpi-row">
        <% loop $Stats %>
        <a class="dashboard-stat ss-kpi-tile" href="$Link" style="text-decoration:none;">
            <div class="dashboard-stat__icon stat-icon--$Color">
                <span class="$Icon ss-icon"></span>
            </div>
            <div class="dashboard-stat__body">
                <div class="dashboard-stat__value">$Value</div>
                <div class="dashboard-stat__label">$Label</div>
                <% if $Delta %>
                <div class="dashboard-stat__delta dashboard-stat__delta--$Trend">$Delta</div>
                <% end_if %>
            </div>
        </a>
        <% end_loop %>
    </div>
</div>

<style>
/* ── SiteSpiderWidget scoped styles ──────────────────────────────────────── */

.ss-spider-widget .dashboard-widget__header {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 20px;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #1e2533;
}

/* Spider emoji icon */
.ss-spider-icon-wrap {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1a1f2e 0%, #0d1117 100%);
    border: 1px solid #2a3450;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ss-spider-icon { font-size: 26px; line-height: 1; }

/* Meta block */
.ss-spider-meta { min-width: 0; }
.ss-spider-meta h3 { margin: 0 0 5px; font-size: 15px; }

/* Session URL + status badge */
.ss-spider-url { margin: 0 0 8px; font-size: 12px; color: #8892a4; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ss-spider-url-link { color: #a0b0d0; text-decoration: none; font-family: 'JetBrains Mono', monospace; font-size: 11px; }
.ss-spider-url-link:hover { color: #fff; text-decoration: underline; }

.ss-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.ss-badge--green   { background: rgba(34,197,94,.15);  color: #22c55e; border: 1px solid rgba(34,197,94,.3); }
.ss-badge--orange  { background: rgba(251,146,60,.15); color: #fb923c; border: 1px solid rgba(251,146,60,.3); }
.ss-badge--red     { background: rgba(239,68,68,.15);  color: #ef4444; border: 1px solid rgba(239,68,68,.3); }
.ss-badge--grey    { background: rgba(148,163,184,.1); color: #94a3b8; border: 1px solid rgba(148,163,184,.2); }

/* Progress bar */
.ss-progress-wrap { display: flex; flex-direction: column; gap: 5px; }
.ss-progress-bar  {
    height: 6px;
    background: #1e2533;
    border-radius: 99px;
    overflow: hidden;
}
.ss-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #6366f1);
    border-radius: 99px;
    transition: width 0.6s cubic-bezier(.4,0,.2,1);
    min-width: 2px;
}
.ss-progress-label { font-size: 11px; color: #5a6378; font-family: 'JetBrains Mono', monospace; }
.ss-progress-pct   { color: #a0b0d0; font-weight: 600; }
.ss-stat-bad       { color: #ef4444; }
.ss-stat-warn      { color: #fb923c; }

/* Empty state */
.ss-spider-empty { margin: 0; font-size: 12px; color: #5a6378; }
.ss-spider-empty a { color: #3b82f6; text-decoration: none; }
.ss-spider-empty a:hover { text-decoration: underline; }

/* Actions */
.ss-spider-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.ss-btn { display: inline-block; padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; transition: opacity .15s; }
.ss-btn-primary { background: linear-gradient(135deg, #3b82f6, #6366f1); color: #fff; }
.ss-btn-primary:hover { opacity: .85; }

/* KPI tiles — 3 columns for this widget */
.ss-kpi-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px 24px 20px;
}
.ss-kpi-tile {
    background: #0d0f14;
    border: 1px solid #1e2533;
    border-radius: 6px;
    padding: 14px 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    transition: border-color .15s, box-shadow .15s;
}
.ss-kpi-tile:hover {
    border-color: #2a3450;
    box-shadow: 0 0 0 1px #2a3450;
}
</style>
