/**
 * SiteSpider Tracker
 * Kalakotra SiteSpider — JS Thin Client
 *
 * Collects Core Web Vitals, performance timing, JS errors and
 * broken resources, then ships a single beacon via sendBeacon().
 *
 * Usage:
 *   <script src="https://sitespider.io/tracker.js?token=YOUR_TOKEN" defer></script>
 *
 * No dependencies. No cookies. No localStorage. GDPR-safe (no PII).
 * Payload: ~2KB JSON. Impact on page performance: zero (defer + sendBeacon).
 */
(function (win, doc) {
    'use strict';

    // ── Token from script src ─────────────────────────────────────────────────
    var token = (function () {
        var scripts = doc.querySelectorAll('script[src*="tracker.js"]');
        for (var i = 0; i < scripts.length; i++) {
            var m = scripts[i].src.match(/[?&]token=([^&]+)/);
            if (m) return m[1];
        }
        return null;
    })();

    if (!token) {
        return; // No token → silent exit
    }

    // ── Beacon endpoint derived from script src ───────────────────────────────
    var endpoint = (function () {
        var scripts = doc.querySelectorAll('script[src*="tracker.js"]');
        if (!scripts.length) return null;
        var src = scripts[0].src;
        var base = src.split('/tracker.js')[0];
        return base + '/api/sitespider/beacon';
    })();

    if (!endpoint) return;

    // ── Payload accumulator ───────────────────────────────────────────────────
    var payload = {
        token:   token,
        url:     win.location.href,
        ref:     doc.referrer || null,

        // Populated below
        cwv:     {},      // Core Web Vitals
        timing:  {},      // Navigation Timing
        errors:  [],      // JS errors (capped at 5)
        broken:  [],      // Broken resources (capped at 20)
        ctx:     {},      // Device / connection context
    };

    var sent = false;

    // ── 1. Context ────────────────────────────────────────────────────────────
    payload.ctx = {
        vw:   win.innerWidth,
        vh:   win.innerHeight,
        dpr:  win.devicePixelRatio || 1,
        conn: (win.navigator.connection || {}).effectiveType || null,
        ua_mobile: /Mobi|Android/i.test(win.navigator.userAgent),
    };

    // ── 2. Navigation Timing (TTFB, FCP) ─────────────────────────────────────
    function collectTiming() {
        if (!win.performance || !win.performance.getEntriesByType) return;

        var nav = win.performance.getEntriesByType('navigation')[0];
        if (nav) {
            payload.timing.ttfb       = Math.round(nav.responseStart - nav.requestStart);
            payload.timing.dns        = Math.round(nav.domainLookupEnd - nav.domainLookupStart);
            payload.timing.tcp        = Math.round(nav.connectEnd - nav.connectStart);
            payload.timing.dom_ready  = Math.round(nav.domContentLoadedEventEnd - nav.startTime);
            payload.timing.load       = Math.round(nav.loadEventEnd - nav.startTime);
            payload.timing.transfer   = nav.transferSize || 0;
            payload.timing.encoded    = nav.encodedBodySize || 0;
        }

        // FCP from paint entries
        var paints = win.performance.getEntriesByType('paint');
        for (var i = 0; i < paints.length; i++) {
            if (paints[i].name === 'first-contentful-paint') {
                payload.cwv.fcp = Math.round(paints[i].startTime);
            }
        }
    }

    // ── 3. Core Web Vitals via PerformanceObserver ────────────────────────────

    // LCP — Largest Contentful Paint
    function observeLCP() {
        if (!win.PerformanceObserver) return;
        try {
            var lcp;
            var obs = new PerformanceObserver(function (list) {
                var entries = list.getEntries();
                lcp = entries[entries.length - 1]; // Last = largest so far
            });
            obs.observe({ type: 'largest-contentful-paint', buffered: true });

            // Finalise on visibility change / unload
            doc.addEventListener('visibilitychange', function () {
                if (doc.visibilityState === 'hidden' && lcp) {
                    payload.cwv.lcp = Math.round(lcp.startTime);
                    obs.disconnect();
                }
            });
        } catch (e) { /* observer not supported */ }
    }

    // CLS — Cumulative Layout Shift
    function observeCLS() {
        if (!win.PerformanceObserver) return;
        try {
            var clsValue = 0;
            var sessionValue = 0;
            var sessionEntries = [];
            var lastEntryTime = 0;

            var obs = new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (!entry.hadRecentInput) {
                        var gap      = entry.startTime - lastEntryTime;
                        var duration = entry.startTime - (sessionEntries[0] || { startTime: 0 }).startTime;

                        if (sessionEntries.length && (gap > 1000 || duration > 5000)) {
                            clsValue    = Math.max(clsValue, sessionValue);
                            sessionValue = 0;
                            sessionEntries = [];
                        }

                        sessionValue += entry.value;
                        sessionEntries.push(entry);
                        lastEntryTime = entry.startTime;
                        payload.cwv.cls = parseFloat(Math.max(clsValue, sessionValue).toFixed(4));
                    }
                });
            });
            obs.observe({ type: 'layout-shift', buffered: true });
        } catch (e) { /* observer not supported */ }
    }

    // INP — Interaction to Next Paint (replaces FID in CWV 2024)
    function observeINP() {
        if (!win.PerformanceObserver) return;
        try {
            var interactions = {};
            var obs = new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry.interactionId) {
                        var id = entry.interactionId;
                        if (!interactions[id]) interactions[id] = { duration: 0 };
                        interactions[id].duration = Math.max(
                            interactions[id].duration,
                            entry.duration
                        );
                    }
                });

                var values = Object.values(interactions).map(function (v) { return v.duration; });
                if (values.length) {
                    values.sort(function (a, b) { return b - a; });
                    // INP = 98th percentile interaction duration
                    var idx = Math.min(
                        Math.floor(values.length * 0.02),
                        values.length - 1
                    );
                    payload.cwv.inp = Math.round(values[idx]);
                }
            });
            obs.observe({ type: 'event', buffered: true, durationThreshold: 16 });
        } catch (e) { /* observer not supported */ }
    }

    // ── 4. JS errors ─────────────────────────────────────────────────────────
    var _origOnError = win.onerror;
    win.onerror = function (msg, src, line, col, err) {
        if (payload.errors.length < 5) {
            payload.errors.push({
                msg:  String(msg).slice(0, 200),
                src:  String(src || '').replace(win.location.origin, ''),
                line: line || 0,
                col:  col  || 0,
            });
        }
        if (typeof _origOnError === 'function') {
            return _origOnError.apply(this, arguments);
        }
        return false;
    };

    // Unhandled promise rejections
    win.addEventListener('unhandledrejection', function (e) {
        if (payload.errors.length < 5) {
            payload.errors.push({
                msg:  String(e.reason || 'Unhandled rejection').slice(0, 200),
                src:  'promise',
                line: 0,
                col:  0,
            });
        }
    });

    // ── 5. Broken resources (404 on images, scripts, CSS, fonts) ─────────────
    doc.addEventListener('error', function (e) {
        var el  = e.target;
        var tag = (el.tagName || '').toLowerCase();

        if (['img', 'script', 'link', 'source', 'audio', 'video'].indexOf(tag) === -1) return;
        if (payload.broken.length >= 20) return;

        var src = el.src || el.href || null;
        if (!src) return;

        payload.broken.push({
            tag: tag,
            src: String(src).replace(win.location.origin, '').slice(0, 300),
        });
    }, true); // capture phase — catches errors before they bubble

    // ── 6. Send beacon ────────────────────────────────────────────────────────
    function send() {
        if (sent) return;
        sent = true;

        collectTiming(); // Collect timing right before send

        var body = JSON.stringify(payload);

        // sendBeacon is non-blocking and survives page unload
        if (win.navigator.sendBeacon) {
            win.navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
            return;
        }

        // Fallback: sync XHR (last resort, only if sendBeacon unavailable)
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', endpoint, false); // sync
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(body);
        } catch (e) { /* silent */ }
    }

    // Send on page hide (tab switch, navigate away, close) — most reliable trigger
    doc.addEventListener('visibilitychange', function () {
        if (doc.visibilityState === 'hidden') send();
    });

    // Fallback triggers
    win.addEventListener('pagehide',   send);
    win.addEventListener('beforeunload', send);

    // Also send after 30s for long-lived pages (SPAs)
    setTimeout(function () {
        if (!sent) send();
    }, 30000);

    // ── Start observers ───────────────────────────────────────────────────────
    observeLCP();
    observeCLS();
    observeINP();

}(window, document));
