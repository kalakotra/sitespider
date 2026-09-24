/**
 * spider-dashboard.js
 * Kalakotra SiteSpider — CMS Dashboard
 *
 * Works in two contexts:
 *   1. Session LIST  — shows session selector dropdown + graph
 *   2. Session DETAIL (/item/N/edit) — auto-loads graph for that session
 *
 * Fetches data from /admin/site-spider/mapdata?session=<id>
 * Dependencies: D3 v7 (loaded dynamically from CDN)
 */

(function () {
    'use strict';

    const MAPDATA_URL = '/admin/site-spider/mapdata';
    const D3_CDN      = 'https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js';
    const NODE_MIN_R  = 5;
    const NODE_MAX_R  = 22;
    const MOUNT_ID    = 'kss-spider-dashboard';

    const COLOR = {
        ok:        '#10b981',
        redirect:  '#f59e0b',
        broken:    '#ef4444',
        orphan:    '#8b5cf6',
        warning:   '#ec4899',
        noindex:   '#f97316',
        sitemap:   '#38bdf8',
        edge:      '#1e3a5a',
        edgeHover: '#3b82f6',
        bg:        '#080d18',
        panel:     '#0d1425',
        border:    '#1a2438',
        text:      '#c9d1e8',
        muted:     '#4a6080',
        accent:    '#0066ff',
    };

    // ── Node colour logic ──────────────────────────────────────────────────────
    function nodeColor(d) {
        if (d.status === 404)                   return COLOR.broken;
        if (d.status >= 301 && d.status <= 308) return COLOR.redirect;
        if (d.noindex)                           return COLOR.noindex;
        if (d.orphan)                            return COLOR.orphan;
        if (d.warning)                           return COLOR.warning;
        return COLOR.ok;
    }

    function statusLabel(s) {
        if (s === 200)             return '✓ 200 OK';
        if (s >= 301 && s <= 308) return `↪ ${s} Redirect`;
        if (s === 404)             return '✗ 404 Not Found';
        if (s === 0)               return '? Timeout / Error';
        return `${s}`;
    }

    // ── Session ID resolution ──────────────────────────────────────────────────

    /** Extract session ID from SilverStripe detail URL: /item/5/edit */
    function resolveSessionIdFromPath() {
        const m = window.location.pathname.match(/\/item\/(\d+)\/(edit|view)/);
        return m ? parseInt(m[1], 10) : null;
    }

    function resolveSessionIdFromQuery() {
        const params = new URLSearchParams(window.location.search);
        const v = params.get('session');
        return v ? parseInt(v, 10) : null;
    }

    function resolveSessionIdFromGrid() {
        const row = document.querySelector('.grid-field tbody tr[data-id], .ss-gridfield-items tr[data-id]');
        return row ? parseInt(row.dataset.id, 10) : null;
    }

    // ── CMS ready polling ──────────────────────────────────────────────────────
    function waitForCMSReady(cb) {
        const poll = setInterval(() => {
            const el = document.querySelector('.cms-content-fields, .cms-edit-form');
            if (el) { clearInterval(poll); cb(el); }
        }, 300);
    }

    // ── Mount container ────────────────────────────────────────────────────────
    function mountContainer(parent) {
        if (document.getElementById(MOUNT_ID)) return document.getElementById(MOUNT_ID);
        const wrap = document.createElement('div');
        wrap.id = MOUNT_ID;
        wrap.className = 'kss-dashboard';
        const grid = parent.querySelector('.grid-field, .ss-gridfield');
        if (grid && grid.parentNode === parent) {
            parent.insertBefore(wrap, grid);
        } else if (grid) {
            grid.parentNode.insertBefore(wrap, grid);
        } else {
            parent.prepend(wrap);
        }
        return wrap;
    }

    // ── Session selector (list view only) ─────────────────────────────────────
    function buildSessionSelector(container, onSelect) {
        const rows = document.querySelectorAll('.grid-field tbody tr[data-id], .ss-gridfield-items tr[data-id]');
        if (!rows.length) return null;

        const sel = document.createElement('select');
        sel.className = 'kss-session-select';
        sel.innerHTML = '<option value="">— Select session to visualise —</option>';

        rows.forEach(row => {
            const id    = row.dataset.id;
            const label = row.querySelector('td')?.textContent?.trim() || `Session #${id}`;
            const opt   = document.createElement('option');
            opt.value   = id;
            opt.textContent = `#${id} — ${label}`;
            sel.appendChild(opt);
        });

        const preselect = resolveSessionIdFromQuery() || resolveSessionIdFromGrid();
        if (preselect) sel.value = preselect;

        sel.addEventListener('change', () => {
            const id = parseInt(sel.value, 10);
            if (id) onSelect(id);
        });

        const wrap = document.createElement('div');
        wrap.className = 'kss-selector-wrap';
        wrap.appendChild(sel);
        container.prepend(wrap);

        return preselect || null;
    }

    // ── Dashboard render ───────────────────────────────────────────────────────
    function renderDashboard(container, sessionId, d3) {
        const old = container.querySelector('.kss-viz-wrap');
        if (old) old.remove();

        const vizWrap = document.createElement('div');
        vizWrap.className = 'kss-viz-wrap';
        container.appendChild(vizWrap);

        vizWrap.innerHTML = `
            <div class="kss-loading">
                <div class="kss-spinner"></div>
                <span>Loading graph for session #${sessionId}…</span>
            </div>`;

        fetch(`${MAPDATA_URL}?session=${sessionId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(r => r.json())
        .then(data => buildGraph(vizWrap, data, d3))
        .catch(err => {
            vizWrap.innerHTML = `<div class="kss-error">⚠ Could not load graph: ${err.message}</div>`;
        });
    }

    // ── D3 force graph ────────────────────────────────────────────────────────
    function buildGraph(container, data, d3) {
        const { nodes, edges } = data;

        if (!nodes.length) {
            container.innerHTML = '<div class="kss-empty">No crawled pages found for this session yet.</div>';
            return;
        }

        container.innerHTML = '';

        // ── Stats bar ──────────────────────────────────────────────────────────
        const stats = {
            total:    nodes.length,
            broken:   nodes.filter(n => n.status === 404).length,
            orphans:  nodes.filter(n => n.orphan).length,
            warnings: nodes.filter(n => n.warning).length,
            redirects:nodes.filter(n => n.status >= 301 && n.status <= 308).length,
            noindex:  nodes.filter(n => n.noindex).length,
            no_https: nodes.filter(n => !n.https).length,
            no_og:    nodes.filter(n => !n.has_og).length,
            ai_done:  nodes.filter(n => n.ai_onpage).length,
        };

        const statsEl = document.createElement('div');
        statsEl.className = 'kss-stats';
        statsEl.innerHTML = `
            <div class="kss-stat" data-filter="all" title="Total crawled pages">
                <span class="kss-stat-value">${stats.total}</span>
                <span class="kss-stat-label">TOTAL PAGES</span>
            </div>
            <div class="kss-stat kss-stat--broken" data-filter="404" title="Pages returning HTTP 404">
                <span class="kss-stat-value">${stats.broken}</span>
                <span class="kss-stat-label">BROKEN 404</span>
            </div>
            <div class="kss-stat kss-stat--orphan" data-filter="orphan" title="Pages with no internal inbound links">
                <span class="kss-stat-value">${stats.orphans}</span>
                <span class="kss-stat-label">ORPHANS</span>
            </div>
            <div class="kss-stat kss-stat--warning" data-filter="warning" title="AI keyword cannibalization warnings">
                <span class="kss-stat-value">${stats.warnings}</span>
                <span class="kss-stat-label">AI CANNIBAL.</span>
            </div>
            <div class="kss-stat kss-stat--redirect" data-filter="redirect" title="Pages returning 3xx redirect">
                <span class="kss-stat-value">${stats.redirects}</span>
                <span class="kss-stat-label">REDIRECTS</span>
            </div>
            <div class="kss-stat kss-stat--noindex" data-filter="noindex" title="Pages with robots noindex">
                <span class="kss-stat-value">${stats.noindex}</span>
                <span class="kss-stat-label">NOINDEX</span>
            </div>
            <div class="kss-stat kss-stat--http" data-filter="http" title="Pages not served over HTTPS">
                <span class="kss-stat-value">${stats.no_https}</span>
                <span class="kss-stat-label">HTTP (no SSL)</span>
            </div>
            <div class="kss-stat kss-stat--og" data-filter="no_og" title="Pages missing Open Graph tags">
                <span class="kss-stat-value">${stats.no_og}</span>
                <span class="kss-stat-label">NO OG TAGS</span>
            </div>
            <div class="kss-stat kss-stat--ai" data-filter="ai" title="Pages with AI on-page analysis">
                <span class="kss-stat-value">${stats.ai_done}</span>
                <span class="kss-stat-label">AI ANALYSED</span>
            </div>`;
        container.appendChild(statsEl);

        // ── Filter bar ─────────────────────────────────────────────────────────
        const filterBar = document.createElement('div');
        filterBar.className = 'kss-filters';
        filterBar.innerHTML = `
            <button class="kss-filter kss-filter--active" data-filter="all">All</button>
            <button class="kss-filter kss-filter--broken"   data-filter="404">✗ 404</button>
            <button class="kss-filter kss-filter--orphan"   data-filter="orphan">◌ Orphans</button>
            <button class="kss-filter kss-filter--warning"  data-filter="warning">⚡ AI Cannibal.</button>
            <button class="kss-filter kss-filter--redirect" data-filter="redirect">↪ Redirects</button>
            <button class="kss-filter kss-filter--noindex"  data-filter="noindex">🚫 Noindex</button>
            <button class="kss-filter kss-filter--http"     data-filter="http">🔓 HTTP</button>
            <button class="kss-filter kss-filter--og"       data-filter="no_og">📭 No OG</button>
            <span class="kss-node-count">${nodes.length} nodes · ${edges.length} edges</span>`;
        container.appendChild(filterBar);

        // ── Layout: graph + panel ──────────────────────────────────────────────
        const layout = document.createElement('div');
        layout.className = 'kss-layout';
        container.appendChild(layout);

        const graphWrap = document.createElement('div');
        graphWrap.className = 'kss-graph-wrap';
        layout.appendChild(graphWrap);

        const panel = document.createElement('div');
        panel.className = 'kss-panel';
        panel.innerHTML = `
            <div class="kss-panel-empty">
                <div class="kss-panel-icon">🔍</div>
                <div>Click a node<br>to inspect</div>
            </div>`;
        layout.appendChild(panel);

        // ── D3 SVG ────────────────────────────────────────────────────────────
        const W = graphWrap.clientWidth || 760;
        const H = Math.max(480, W * 0.58);

        const svg = d3.select(graphWrap)
            .append('svg')
            .attr('width', '100%')
            .attr('height', H)
            .attr('viewBox', `0 0 ${W} ${H}`)
            .style('display', 'block');

        svg.append('defs').html(`
            <marker id="kss-arrow" markerWidth="6" markerHeight="4"
                refX="18" refY="2" orient="auto">
                <polygon points="0 0, 6 2, 0 4" fill="${COLOR.edge}" />
            </marker>
            <filter id="kss-glow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="3" result="blur"/>
                <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>`);

        svg.append('rect')
            .attr('width', W).attr('height', H)
            .attr('fill', COLOR.bg).attr('rx', 8);

        const maxInbound = d3.max(nodes, d => d.inbound) || 1;
        const rScale = d3.scaleSqrt()
            .domain([0, maxInbound])
            .range([NODE_MIN_R, NODE_MAX_R]);

        let activeFilter  = 'all';
        let filteredNodes = [...nodes];
        let filteredEdges = [...edges];

        const simulation = d3.forceSimulation(filteredNodes)
            .force('link',    d3.forceLink(filteredEdges).id(d => d.id).distance(60).strength(0.4))
            .force('charge',  d3.forceManyBody().strength(-320))
            .force('center',  d3.forceCenter(W / 2, H / 2))
            .force('collide', d3.forceCollide(d => rScale(d.inbound) + 4));

        const linkLayer  = svg.append('g').attr('class', 'kss-edges');
        const nodeLayer  = svg.append('g').attr('class', 'kss-nodes');
        const labelLayer = svg.append('g').attr('class', 'kss-labels');

        let linkSel, nodeSel, labelSel;
        let selectedNode = null;

        function matchFilter(n, f) {
            if (f === '404')      return n.status === 404;
            if (f === 'orphan')   return n.orphan;
            if (f === 'warning')  return n.warning;
            if (f === 'redirect') return n.status >= 301 && n.status <= 308;
            if (f === 'noindex')  return n.noindex;
            if (f === 'http')     return !n.https;
            if (f === 'no_og')    return !n.has_og;
            if (f === 'ai')       return n.ai_onpage;
            return true;
        }

        function applyFilter(filter) {
            activeFilter  = filter;
            filteredNodes = nodes.filter(n => matchFilter(n, filter));
            const ids     = new Set(filteredNodes.map(n => n.id));
            filteredEdges = edges.filter(e =>
                ids.has(e.source.id ?? e.source) && ids.has(e.target.id ?? e.target)
            );
            filterBar.querySelector('.kss-node-count').textContent =
                `${filteredNodes.length} nodes · ${filteredEdges.length} edges`;
            rebind();
        }

        function rebind() {
            filteredNodes.forEach(n => {
                if (n.x === undefined) {
                    n.x = W / 2 + (Math.random() - 0.5) * 200;
                    n.y = H / 2 + (Math.random() - 0.5) * 200;
                }
            });

            linkSel = linkLayer.selectAll('line')
                .data(filteredEdges, d => `${d.source.id ?? d.source}-${d.target.id ?? d.target}`)
                .join(
                    e => e.append('line')
                        .attr('stroke', COLOR.edge).attr('stroke-width', 1)
                        .attr('marker-end', 'url(#kss-arrow)').attr('opacity', 0.35),
                    u => u,
                    x => x.remove()
                );

            nodeSel = nodeLayer.selectAll('circle')
                .data(filteredNodes, d => d.id)
                .join(
                    e => e.append('circle')
                        .attr('r', d => rScale(d.inbound))
                        .attr('fill', nodeColor).attr('stroke', nodeColor)
                        .attr('stroke-width', 0.8).attr('opacity', 0.88)
                        .style('cursor', 'pointer')
                        .call(d3.drag()
                            .on('start', (ev, d) => { if (!ev.active) simulation.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
                            .on('drag',  (ev, d) => { d.fx = ev.x; d.fy = ev.y; })
                            .on('end',   (ev, d) => { if (!ev.active) simulation.alphaTarget(0); d.fx = null; d.fy = null; }))
                        .on('mouseover', onNodeHover)
                        .on('mouseout',  onNodeOut)
                        .on('click',     onNodeClick),
                    u => u.attr('r', d => rScale(d.inbound)).attr('fill', nodeColor),
                    x => x.remove()
                );

            labelSel = labelLayer.selectAll('text')
                .data(filteredNodes.filter(n => n.inbound > 5), d => d.id)
                .join(
                    e => e.append('text')
                        .attr('text-anchor', 'middle').attr('font-size', 9)
                        .attr('fill', COLOR.muted).attr('pointer-events', 'none')
                        .text(d => d.title.length > 16 ? d.title.slice(0, 15) + '…' : d.title),
                    u => u.text(d => d.title.length > 16 ? d.title.slice(0, 15) + '…' : d.title),
                    x => x.remove()
                );

            simulation.nodes(filteredNodes).force('link').links(filteredEdges);
            simulation.alpha(0.4).restart();
        }

        simulation.on('tick', () => {
            if (linkSel) {
                linkSel
                    .attr('x1', d => d.source.x).attr('y1', d => d.source.y)
                    .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
            }
            if (nodeSel) {
                nodeSel
                    .attr('cx', d => Math.max(NODE_MAX_R, Math.min(W - NODE_MAX_R, d.x)))
                    .attr('cy', d => Math.max(NODE_MAX_R, Math.min(H - NODE_MAX_R, d.y)));
            }
            if (labelSel) {
                labelSel
                    .attr('x', d => Math.max(NODE_MAX_R, Math.min(W - NODE_MAX_R, d.x)))
                    .attr('y', d => Math.max(NODE_MAX_R, Math.min(H - NODE_MAX_R, d.y)) + rScale(d.inbound) + 11);
            }
        });

        rebind();

        // ── Hover & click ──────────────────────────────────────────────────────
        let tooltip = document.getElementById('kss-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'kss-tooltip';
            tooltip.className = 'kss-tooltip';
            document.body.appendChild(tooltip);
        }

        function onNodeHover(event, d) {
            nodeSel.attr('opacity', n => n.id === d.id ? 1 : 0.2);
            linkSel
                .attr('opacity', l => {
                    const s = l.source.id ?? l.source, t = l.target.id ?? l.target;
                    return s === d.id || t === d.id ? 0.9 : 0.05;
                })
                .attr('stroke', l => {
                    const s = l.source.id ?? l.source, t = l.target.id ?? l.target;
                    return s === d.id || t === d.id ? COLOR.edgeHover : COLOR.edge;
                });

            const issues = [];
            if (d.status === 404)                   issues.push('<span style="color:#ef4444">✗ 404</span>');
            if (d.noindex)                           issues.push('<span style="color:#f97316">🚫 noindex</span>');
            if (d.orphan)                            issues.push('<span style="color:#8b5cf6">◌ orphan</span>');
            if (!d.https)                            issues.push('<span style="color:#f97316">🔓 HTTP</span>');
            if (d.warning)                           issues.push('<span style="color:#ec4899">⚡ AI</span>');

            tooltip.innerHTML = `
                <strong>${d.title}</strong><br>
                ${statusLabel(d.status)} &nbsp;·&nbsp; ↗${d.inbound} ↘${d.outbound} &nbsp;·&nbsp; ${d.ms}ms
                ${issues.length ? '<br>' + issues.join(' &nbsp; ') : ''}`;
            tooltip.style.display = 'block';
            positionTooltip(event);
        }

        function onNodeOut() {
            tooltip.style.display = 'none';
            nodeSel.attr('opacity', 0.88);
            linkSel.attr('opacity', 0.35).attr('stroke', COLOR.edge);
        }

        function onNodeClick(event, d) {
            event.stopPropagation();
            selectedNode = d;
            nodeSel
                .attr('stroke-width', n => n.id === d.id ? 2.5 : 0.8)
                .attr('filter',       n => n.id === d.id ? 'url(#kss-glow)' : null)
                .attr('stroke',       n => n.id === d.id ? '#fff' : nodeColor(n));
            renderPanel(d);
        }

        svg.on('click', () => {
            if (selectedNode) {
                selectedNode = null;
                nodeSel.attr('stroke-width', 0.8).attr('filter', null).attr('stroke', nodeColor);
                panel.innerHTML = `<div class="kss-panel-empty"><div class="kss-panel-icon">🔍</div><div>Click a node<br>to inspect</div></div>`;
            }
        });

        svg.on('mousemove', positionTooltip);

        function positionTooltip(event) {
            tooltip.style.left = (event.pageX + 14) + 'px';
            tooltip.style.top  = (event.pageY - 10) + 'px';
        }

        // ── Detail panel ───────────────────────────────────────────────────────
        function seoRow(label, val, color, issue) {
            return `<div class="kss-panel-row">
                <span class="kss-panel-row-label">${label}</span>
                <span class="kss-panel-row-val${issue ? ' kss-panel-row-issue' : ''}" style="color:${color}">${val}</span>
            </div>`;
        }

        function renderPanel(d) {
            const c = nodeColor(d);

            const titleColor = d.title_len === 0 ? COLOR.broken : (d.title_len < 30 || d.title_len > 60) ? COLOR.redirect : COLOR.ok;
            const descColor  = d.desc_len  === 0 ? COLOR.broken : d.desc_len > 160 ? COLOR.redirect : COLOR.ok;
            const h1Color    = d.h1_count  === 0 ? COLOR.broken : d.h1_count > 1   ? COLOR.redirect : COLOR.ok;
            const titleLabel = d.title_len === 0 ? '✗ Missing' : `${d.title_len} chars${d.title_len < 30 ? ' ⚠ short' : d.title_len > 60 ? ' ⚠ long' : ''}`;
            const descLabel  = d.desc_len  === 0 ? '✗ Missing' : `${d.desc_len} chars${d.desc_len > 160 ? ' ⚠ long' : ''}`;
            const h1Label    = d.h1_count  === 0 ? '✗ Missing' : d.h1_count > 1 ? `⚠ ${d.h1_count} found` : '✓ 1';

            panel.innerHTML = `
                <div class="kss-panel-header" style="border-color:${c}44">
                    <div class="kss-panel-title">${d.title}</div>
                    <div class="kss-panel-url">${d.url}</div>
                </div>
                <div class="kss-panel-section">HTTP &amp; PERFORMANCE</div>
                <div class="kss-panel-rows">
                    ${seoRow('Status',    statusLabel(d.status), c, d.status !== 200)}
                    ${seoRow('HTTPS',     d.https ? '✓ Secure' : '✗ HTTP only', d.https ? COLOR.ok : COLOR.broken, !d.https)}
                    ${seoRow('Speed',     d.ms + ' ms', d.ms > 1500 ? COLOR.redirect : d.ms > 3000 ? COLOR.broken : COLOR.ok, d.ms > 1500)}
                    ${seoRow('Robots',    d.noindex ? '🚫 noindex' : '✓ OK', d.noindex ? COLOR.noindex : COLOR.ok, d.noindex)}
                </div>
                <div class="kss-panel-section">ON-PAGE SEO</div>
                <div class="kss-panel-rows">
                    ${seoRow('Title',       titleLabel, titleColor, titleColor !== COLOR.ok)}
                    ${seoRow('Description', descLabel,  descColor,  descColor  !== COLOR.ok)}
                    ${seoRow('H1',          h1Label,    h1Color,    h1Color    !== COLOR.ok)}
                    ${seoRow('Words',       d.words + ' words', d.words < 300 ? COLOR.muted : COLOR.ok, false)}
                    ${seoRow('Imgs no alt', d.img_no_alt > 0 ? `⚠ ${d.img_no_alt}` : '✓ OK', d.img_no_alt > 0 ? COLOR.redirect : COLOR.ok, d.img_no_alt > 0)}
                </div>
                <div class="kss-panel-section">LINKS &amp; SOCIAL</div>
                <div class="kss-panel-rows">
                    ${seoRow('Inbound',   d.inbound,  '#60a5fa', false)}
                    ${seoRow('Outbound',  d.outbound, '#34d399', false)}
                    ${seoRow('Orphan',    d.orphan ? '⚠ Yes' : 'No', d.orphan ? COLOR.orphan : COLOR.muted, d.orphan)}
                    ${seoRow('OG Tags',   d.has_og ? '✓ Set' : '✗ Missing', d.has_og ? COLOR.ok : COLOR.broken, !d.has_og)}
                    ${seoRow('Sitemap',   d.sitemap ? '✓ Yes' : 'Discovered', d.sitemap ? COLOR.sitemap : COLOR.muted, false)}
                </div>
                <div class="kss-panel-section">AI ANALYSIS</div>
                <div class="kss-panel-rows">
                    ${seoRow('On-Page',         d.ai_onpage ? '✓ Done' : '— Pending', d.ai_onpage ? COLOR.ok : COLOR.muted, false)}
                    ${seoRow('Cannibalization',  d.warning   ? '⚡ Warning' : '✓ Clear', d.warning ? COLOR.warning : COLOR.ok, d.warning)}
                </div>
                <a class="kss-panel-open" href="${d.url}" target="_blank" rel="noopener">Open page ↗</a>`;
        }

        // ── Filter buttons ─────────────────────────────────────────────────────
        filterBar.querySelectorAll('.kss-filter').forEach(btn => {
            btn.addEventListener('click', () => {
                filterBar.querySelectorAll('.kss-filter').forEach(b => b.classList.remove('kss-filter--active'));
                btn.classList.add('kss-filter--active');
                applyFilter(btn.dataset.filter);
            });
        });

        statsEl.querySelectorAll('.kss-stat[data-filter]').forEach(el => {
            el.style.cursor = 'pointer';
            el.addEventListener('click', () => {
                const f = el.dataset.filter;
                filterBar.querySelectorAll('.kss-filter').forEach(b =>
                    b.classList.toggle('kss-filter--active', b.dataset.filter === f)
                );
                applyFilter(f);
            });
        });

        // ── Legend ─────────────────────────────────────────────────────────────
        const legend = document.createElement('div');
        legend.className = 'kss-legend';
        legend.innerHTML = [
            [COLOR.ok,       '200 OK'],
            [COLOR.redirect, 'Redirect'],
            [COLOR.broken,   '404'],
            [COLOR.noindex,  'Noindex'],
            [COLOR.orphan,   'Orphan'],
            [COLOR.warning,  'AI ⚡'],
        ].map(([c, l]) =>
            `<span class="kss-legend-item">
                <span class="kss-legend-dot" style="background:${c};box-shadow:0 0 5px ${c}88"></span>${l}
            </span>`
        ).join('') + '<span class="kss-legend-note">Node size = inbound links</span>';
        container.appendChild(legend);
    }

    // ── D3 loader ──────────────────────────────────────────────────────────────
    function loadD3(cb) {
        if (window.d3) { cb(window.d3); return; }
        const s = document.createElement('script');
        s.src     = D3_CDN;
        s.onload  = () => cb(window.d3);
        s.onerror = () => console.error('SiteSpider: failed to load D3 from CDN');
        document.head.appendChild(s);
    }

    // ── Bootstrap ──────────────────────────────────────────────────────────────
    function init() {
        if (!window.location.pathname.includes('/admin/site-spider')) return;

        waitForCMSReady(contentArea => {
            loadD3(d3 => {
                const container = mountContainer(contentArea);

                // Detail page: session ID is in the URL path (/item/N/edit)
                const pathId = resolveSessionIdFromPath();
                if (pathId) {
                    renderDashboard(container, pathId, d3);
                    return;
                }

                // List page: build selector from GridField rows
                const preselect = buildSessionSelector(container, id => renderDashboard(container, id, d3));
                if (preselect) renderDashboard(container, preselect, d3);
            });
        });
    }

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', init)
        : init();

    document.addEventListener('ajaxComplete', init);
    window.addEventListener('popstate', init);

})();
