<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SEO Audit — $Session.BaseURL</title>
<style>
:root {
    --bg:       #080d18;
    --surface:  #0d1425;
    --surface2: #060c18;
    --border:   #1a2438;
    --border2:  #1e3050;
    --text:     #c9d1e8;
    --muted:    #4a6080;
    --accent:   #0066ff;
    --ok:       #10b981;
    --redir:    #f59e0b;
    --broken:   #ef4444;
    --orphan:   #8b5cf6;
    --warning:  #ec4899;
    --sitemap:  #38bdf8;
    --noindex:  #f97316;
    --r:        10px;
    --r-sm:     6px;
    --font:     'DM Mono','Fira Mono','Courier New',monospace;
}
*  { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 13px; line-height: 1.5; }
a    { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

.report { max-width: 1440px; margin: 0 auto; padding: 32px 20px 80px; }

/* ── Header ─────────────────────────────────────────────────────────────────── */
.rh {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 28px 32px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}
.rh::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--orphan), var(--ok));
}
.rh-brand { font-size: 10px; letter-spacing: .15em; color: var(--muted); text-transform: uppercase; margin-bottom: 8px; }
.rh-site  { font-size: 22px; font-weight: 800; color: #e2e8f0; word-break: break-all; margin-bottom: 10px; }
.rh-meta  { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; font-size: 11px; color: var(--muted); }
.rh-meta .sep { color: var(--border2); }

.badge { font-size: 10px; letter-spacing: .08em; text-transform: uppercase; padding: 3px 10px; border-radius: 100px; font-weight: 700; }
.badge--completed { background: #10b98118; color: var(--ok);     border: 1px solid #10b98140; }
.badge--running   { background: #0066ff18; color: var(--accent); border: 1px solid #0066ff40; }
.badge--pending   { background: #4a608018; color: var(--muted);  border: 1px solid #4a608040; }
.badge--failed    { background: #ef444418; color: var(--broken); border: 1px solid #ef444440; }

/* ── Stats grid ─────────────────────────────────────────────────────────────── */
.stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 10px; margin-bottom: 24px; }
.stat {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--r);
    padding: 14px 16px; position: relative; overflow: hidden;
}
.stat::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; }
.stat-v { display: block; font-size: 28px; font-weight: 800; line-height: 1; color: #60a5fa; margin-bottom: 4px; }
.stat-l { display: block; font-size: 9px; letter-spacing: .1em; color: var(--muted); text-transform: uppercase; }
.stat-v small { font-size: 14px; font-weight: 400; }

.s-broken  { border-color: #ef444428; } .s-broken  .stat-v { color: var(--broken); }  .s-broken::after  { background: linear-gradient(90deg,var(--broken),transparent); }
.s-orphan  { border-color: #8b5cf628; } .s-orphan  .stat-v { color: var(--orphan); }  .s-orphan::after  { background: linear-gradient(90deg,var(--orphan),transparent); }
.s-redir   { border-color: #f59e0b28; } .s-redir   .stat-v { color: var(--redir); }   .s-redir::after   { background: linear-gradient(90deg,var(--redir),transparent); }
.s-noindex { border-color: #f9731628; } .s-noindex .stat-v { color: var(--noindex); } .s-noindex::after { background: linear-gradient(90deg,var(--noindex),transparent); }
.s-warn    { border-color: #ec489928; } .s-warn    .stat-v { color: var(--warning); } .s-warn::after    { background: linear-gradient(90deg,var(--warning),transparent); }
.s-ok      { border-color: #10b98128; } .s-ok      .stat-v { color: var(--ok); }      .s-ok::after      { background: linear-gradient(90deg,var(--ok),transparent); }

/* ── Section ────────────────────────────────────────────────────────────────── */
.section { margin-bottom: 24px; }
.sec-title {
    font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted);
    padding: 10px 14px; background: var(--surface2);
    border: 1px solid var(--border); border-bottom: none;
    border-radius: var(--r-sm) var(--r-sm) 0 0;
    display: flex; align-items: center; gap: 10px;
    border-left-width: 3px;
}
.sec-title .cnt {
    background: var(--surface); border: 1px solid var(--border);
    padding: 1px 8px; border-radius: 100px; font-size: 10px; color: var(--text);
}
.t-broken  { border-left-color: var(--broken); }
.t-orphan  { border-left-color: var(--orphan); }
.t-redir   { border-left-color: var(--redir); }
.t-noindex { border-left-color: var(--noindex); }
.t-seo     { border-left-color: var(--sitemap); }
.t-ai      { border-left-color: var(--warning); }
.t-all     { border-left-color: var(--accent); }

/* ── Table ──────────────────────────────────────────────────────────────────── */
.tw { overflow-x: auto; border: 1px solid var(--border); border-radius: 0 0 var(--r-sm) var(--r-sm); }
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead th {
    background: var(--surface2); color: var(--muted);
    font-size: 9px; letter-spacing: .08em; text-transform: uppercase;
    padding: 9px 12px; text-align: left; white-space: nowrap;
    border-bottom: 1px solid var(--border); cursor: pointer; user-select: none;
}
thead th:hover { color: var(--text); }
thead th.sa::after { content: ' ↑'; color: var(--accent); }
thead th.sd::after { content: ' ↓'; color: var(--accent); }
tbody tr { border-bottom: 1px solid #0a1220; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #0d1828; }
tbody tr.hidden { display: none; }
td { padding: 8px 12px; vertical-align: middle; }
.uc { max-width: 480px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.uc a { color: var(--text); font-size: 11px; }
.uc a:hover { color: var(--accent); }

/* ── Chips ──────────────────────────────────────────────────────────────────── */
.chip { display: inline-block; padding: 2px 7px; border-radius: 100px; font-size: 9px; font-weight: 700; letter-spacing: .04em; white-space: nowrap; }
.c-ok      { background: #10b98118; color: var(--ok); }
.c-broken  { background: #ef444418; color: var(--broken); }
.c-redir   { background: #f59e0b18; color: var(--redir); }
.c-warn    { background: #ec489918; color: var(--warning); }
.c-orphan  { background: #8b5cf618; color: var(--orphan); }
.c-noindex { background: #f9731618; color: var(--noindex); }
.c-muted   { background: #1a243818; color: var(--muted); }

/* ── Filter bar ─────────────────────────────────────────────────────────────── */
.fb {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    padding: 10px 14px; background: var(--surface2);
    border: 1px solid var(--border); border-bottom: none; border-top: none;
}
.fb-btn {
    background: transparent; border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--muted); font-family: var(--font); font-size: 10px;
    letter-spacing: .04em; padding: 4px 12px; cursor: pointer; transition: all .15s;
}
.fb-btn:hover { border-color: var(--accent); color: var(--text); }
.fb-btn.on    { background: #0066ff18; border-color: var(--accent); color: var(--accent); }
.fb-count     { margin-left: auto; font-size: 10px; color: var(--muted); white-space: nowrap; }
.limit-note   { font-size: 10px; color: var(--muted); padding: 8px 14px; background: var(--surface2); border: 1px solid var(--border); border-bottom: none; border-top: none; }

/* ── AI warning text ────────────────────────────────────────────────────────── */
.ai-txt { font-size: 10px; color: #c084a0; line-height: 1.6; max-width: 600px; }

/* ── Footer ─────────────────────────────────────────────────────────────────── */
.footer {
    margin-top: 48px; padding-top: 20px;
    border-top: 1px solid var(--border);
    font-size: 10px; color: var(--muted);
    display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}

@media (max-width: 768px) {
    .stats { grid-template-columns: repeat(3,1fr); }
    .rh    { padding: 20px; }
}
@media (max-width: 480px) {
    .stats   { grid-template-columns: repeat(2,1fr); }
    .rh-meta { flex-direction: column; gap: 6px; }
}
</style>
</head>
<body>
<div class="report">

<%-- Header --%>
<header class="rh">
    <div class="rh-brand">Kalakotra / SEO Audit Report</div>
    <div class="rh-site">$Session.BaseURL</div>
    <div class="rh-meta">
        <span>Crawled: $Session.StartedAt.Nice</span>
        <span class="sep">·</span>
        <span>Duration: $Session.Duration</span>
        <span class="sep">·</span>
        <span class="badge badge--{$Session.Status}">$Session.Status</span>
    </div>
</header>

<%-- Stats --%>
<div class="stats">
    <div class="stat">
        <span class="stat-v">$Session.CrawledPages</span>
        <span class="stat-l">Pages Crawled</span>
    </div>
    <div class="stat <% if $BrokenPages.Count %>s-broken<% end_if %>">
        <span class="stat-v">$BrokenPages.Count</span>
        <span class="stat-l">Broken (404)</span>
    </div>
    <div class="stat <% if $OrphanPages.Count %>s-orphan<% end_if %>">
        <span class="stat-v">$OrphanPages.Count</span>
        <span class="stat-l">Orphan Pages</span>
    </div>
    <div class="stat <% if $RedirectPages.Count %>s-redir<% end_if %>">
        <span class="stat-v">$RedirectPages.Count</span>
        <span class="stat-l">Redirects</span>
    </div>
    <div class="stat <% if $NoIndexPages.Count %>s-noindex<% end_if %>">
        <span class="stat-v">$NoIndexPages.Count</span>
        <span class="stat-l">NoIndex</span>
    </div>
    <div class="stat <% if $NoTitlePages.Count %>s-warn<% end_if %>">
        <span class="stat-v">$NoTitlePages.Count</span>
        <span class="stat-l">Missing Title</span>
    </div>
    <div class="stat <% if $NoMetaPages.Count %>s-warn<% end_if %>">
        <span class="stat-v">$NoMetaPages.Count</span>
        <span class="stat-l">Missing Meta</span>
    </div>
    <div class="stat <% if $AIWarnings.Count %>s-warn<% end_if %>">
        <span class="stat-v">$AIWarnings.Count</span>
        <span class="stat-l">AI Warnings</span>
    </div>
    <div class="stat s-ok">
        <span class="stat-v">$AvgResponseMs<small> ms</small></span>
        <span class="stat-l">Avg Response</span>
    </div>
</div>

<%-- Broken pages --%>
<% if $BrokenPages.Count %>
<div class="section">
    <div class="sec-title t-broken">Broken Pages (404) <span class="cnt">$BrokenPages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Response (ms)</th><th>Inbound Links</th></tr></thead>
            <tbody>
                <% loop $BrokenPages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>$ResponseTimeMs</td>
                    <td>$InboundLinksCount</td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- Orphan pages --%>
<% if $OrphanPages.Count %>
<div class="section">
    <div class="sec-title t-orphan">Orphan Pages <span class="cnt">$OrphanPages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Title</th><th>Source</th></tr></thead>
            <tbody>
                <% loop $OrphanPages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>$PageTitle</td>
                    <td>$IsFromSitemapNice</td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- Redirects --%>
<% if $RedirectPages.Count %>
<div class="section">
    <div class="sec-title t-redir">Redirects <span class="cnt">$RedirectPages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Status</th><th>Target</th></tr></thead>
            <tbody>
                <% loop $RedirectPages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td><span class="chip c-redir">$HttpStatus</span></td>
                    <td class="uc">
                        <% if $RedirectTarget %>
                            <a href="$RedirectTarget" target="_blank" rel="noopener noreferrer">$RedirectTarget</a>
                        <% else %>—<% end_if %>
                    </td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- NoIndex pages --%>
<% if $NoIndexPages.Count %>
<div class="section">
    <div class="sec-title t-noindex">NoIndex Pages <span class="cnt">$NoIndexPages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Title</th><th>Canonical</th></tr></thead>
            <tbody>
                <% loop $NoIndexPages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>$PageTitle</td>
                    <td class="uc">$CanonicalUrl</td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- Missing title --%>
<% if $NoTitlePages.Count %>
<div class="section">
    <div class="sec-title t-seo">Missing Page Title <span class="cnt">$NoTitlePages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>H1</th><th>Words</th></tr></thead>
            <tbody>
                <% loop $NoTitlePages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>$H1</td>
                    <td>$WordCount</td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- Missing meta description --%>
<% if $NoMetaPages.Count %>
<div class="section">
    <div class="sec-title t-seo">Missing Meta Description <span class="cnt">$NoMetaPages.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Title</th><th>Words</th></tr></thead>
            <tbody>
                <% loop $NoMetaPages %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>$PageTitle</td>
                    <td>$WordCount</td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- AI cannibalization warnings --%>
<% if $AIWarnings.Count %>
<div class="section">
    <div class="sec-title t-ai">AI Cannibalization Warnings <span class="cnt">$AIWarnings.Count</span></div>
    <div class="tw">
        <table>
            <thead><tr><th>URL</th><th>Warning</th></tr></thead>
            <tbody>
                <% loop $AIWarnings %>
                <tr>
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td><div class="ai-txt">$KeywordCannibalizationWarning</div></td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>
<% end_if %>

<%-- All pages table --%>
<div class="section">
    <div class="sec-title t-all">
        All Pages
        <span class="cnt" id="pgCnt">$AllPagesCount</span>
    </div>
    <div class="fb">
        <button class="fb-btn on"  data-f="all">All</button>
        <button class="fb-btn"     data-f="broken">404 Broken</button>
        <button class="fb-btn"     data-f="orphan">Orphan</button>
        <button class="fb-btn"     data-f="redirect">Redirect</button>
        <button class="fb-btn"     data-f="noindex">NoIndex</button>
        <button class="fb-btn"     data-f="ai">AI Warning</button>
        <span class="fb-count" id="visCount"></span>
    </div>
    <% if $AllPagesLimited %>
    <div class="limit-note">Showing top 1,000 of $AllPagesCount pages by inbound links.</div>
    <% end_if %>
    <div class="tw">
        <table id="pgTable">
            <thead><tr>
                <th data-s="str">URL</th>
                <th data-s="num">HTTP</th>
                <th data-s="str">Title</th>
                <th data-s="str">Meta</th>
                <th data-s="str">H1</th>
                <th data-s="num">↗ In</th>
                <th data-s="num">ms</th>
                <th>Issues</th>
            </tr></thead>
            <tbody>
                <% loop $AllPages %>
                <tr data-flags="$ReportFlags">
                    <td class="uc"><a href="$URL" target="_blank" rel="noopener noreferrer">$URL</a></td>
                    <td>
                        <% if $HttpStatus == 200 %><span class="chip c-ok">200</span>
                        <% else_if $HttpStatus == 404 %><span class="chip c-broken">404</span>
                        <% else_if $HttpStatus >= 500 %><span class="chip c-broken">$HttpStatus</span>
                        <% else_if $HttpStatus >= 300 %><span class="chip c-redir">$HttpStatus</span>
                        <% else %><span class="chip c-muted">$HttpStatus</span>
                        <% end_if %>
                    </td>
                    <td>$TitleIssueBadge</td>
                    <td>$MetaDescIssueBadge</td>
                    <td>$H1Badge</td>
                    <td>$InboundLinksCount</td>
                    <td>$ResponseTimeMs</td>
                    <td>
                        <% if $IsOrphan %><span class="chip c-orphan">orphan</span> <% end_if %>
                        <% if $RobotsNoIndex %><span class="chip c-noindex">noindex</span> <% end_if %>
                        <% if $KeywordCannibalizationWarning %><span class="chip c-warn">AI ⚡</span><% end_if %>
                    </td>
                </tr>
                <% end_loop %>
            </tbody>
        </table>
    </div>
</div>

<footer class="footer">
    <span>Generated by Kalakotra SiteSpider</span>
    <span>$Session.BaseURL · $Session.StartedAt.Nice</span>
</footer>

</div>
<script>
(function () {
    var btns     = document.querySelectorAll('.fb-btn');
    var rows     = document.querySelectorAll('#pgTable tbody tr');
    var visCount = document.getElementById('visCount');
    var active   = 'all';

    function filter(f) {
        active = f;
        var n = 0;
        rows.forEach(function (r) {
            var flags = r.dataset.flags || '';
            var show  = f === 'all' || flags.split(' ').indexOf(f) !== -1;
            r.classList.toggle('hidden', !show);
            if (show) n++;
        });
        visCount.textContent = f !== 'all' ? n + ' shown' : '';
    }

    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            btns.forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            filter(b.dataset.f);
        });
    });

    var sortCol = -1, sortDir = 1;
    document.querySelectorAll('#pgTable thead th[data-s]').forEach(function (th, i) {
        th.addEventListener('click', function () {
            sortDir = sortCol === i ? -sortDir : 1;
            sortCol = i;
            document.querySelectorAll('#pgTable thead th').forEach(function (h) {
                h.classList.remove('sa', 'sd');
            });
            th.classList.add(sortDir === 1 ? 'sa' : 'sd');

            var tbody = document.querySelector('#pgTable tbody');
            var all   = Array.from(tbody.querySelectorAll('tr'));
            var type  = th.dataset.s;

            all.sort(function (a, b) {
                var av = (a.cells[i] || {}).textContent || '';
                var bv = (b.cells[i] || {}).textContent || '';
                av = av.trim(); bv = bv.trim();
                if (type === 'num') return (parseFloat(av) - parseFloat(bv)) * sortDir;
                return av.localeCompare(bv) * sortDir;
            });

            all.forEach(function (r) { tbody.appendChild(r); });
            filter(active);
        });
    });
}());
</script>
</body>
</html>
