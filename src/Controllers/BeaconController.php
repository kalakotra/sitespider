<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Controllers;

use Kalakotra\SiteSpider\Models\AuditPage;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Models\BeaconLog;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;

/**
 * BeaconController — receives POST /api/sitespider/beacon from tracker.js.
 *
 * Flow:
 *   1. Validate token → resolve AuditSession
 *   2. Validate + sanitise payload
 *   3. Write BeaconLog row
 *   4. Join to AuditPage by URL (create stub if not yet crawled)
 *   5. If sample count >= min_samples: push p75 CWV back to AuditPage
 *
 * Returns 204 No Content on success (sendBeacon ignores response body).
 *
 * YAML config:
 *   Kalakotra\SiteSpider\Controllers\BeaconController:
 *     min_samples: 10          # Minimum beacons before writing p75 to AuditPage
 *     max_errors_stored: 5     # Per beacon
 *     max_broken_stored: 20    # Per beacon
 */
class BeaconController extends Controller
{
    use Configurable;

    private static int $min_samples      = 10;
    private static int $max_errors_stored = 5;
    private static int $max_broken_stored = 20;

    private static array $allowed_actions = ['index'];

    private static string $url_segment = 'api/sitespider/beacon';

    // Origin of the current request, reflected back in CORS headers (sendBeacon always sends credentials mode, so '*' is rejected by browsers)
    private ?string $requestOrigin = null;

    public function index(HTTPRequest $request): HTTPResponse
    {
        $this->requestOrigin = $request->getHeader('Origin') ?: '*';

        // ── CORS preflight ────────────────────────────────────────────────────
        if ($request->httpMethod() === 'OPTIONS') {
            return $this->corsResponse();
        }

        // ── Only accept POST ──────────────────────────────────────────────────
        if (!$request->isPOST()) {
            return $this->respond(405);
        }

        // ── Parse body ────────────────────────────────────────────────────────
        $body = $request->getBody();
        if (!$body) {
            return $this->respond(400);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $this->respond(400);
        }

        // ── Validate token ────────────────────────────────────────────────────
        $token = $this->sanitiseString($data['token'] ?? '');
        if (!$token) {
            return $this->respond(401);
        }

        $session = AuditSession::get()
            ->filter(['ApiToken' => $token, 'Status' => ['running', 'completed']])
            ->first();

        if (!$session) {
            return $this->respond(401);
        }

        // ── Validate URL ──────────────────────────────────────────────────────
        $url = filter_var($data['url'] ?? '', FILTER_VALIDATE_URL);
        if (!$url) {
            return $this->respond(400);
        }

        $url = $this->normaliseUrl($url);

        // ── Write BeaconLog ───────────────────────────────────────────────────
        $log = BeaconLog::create();
        $log->URL   = $url;
        $log->Token = $token;

        // CWV
        $cwv          = $data['cwv'] ?? [];
        $log->LCP     = $this->positiveInt($cwv['lcp'] ?? null);
        $log->CLS     = $this->boundedFloat($cwv['cls'] ?? null, 0, 5);
        $log->INP     = $this->positiveInt($cwv['inp'] ?? null);
        $log->FCP     = $this->positiveInt($cwv['fcp'] ?? null);

        // Timing
        $timing         = $data['timing'] ?? [];
        $log->TTFB      = $this->positiveInt($timing['ttfb'] ?? null);
        $log->DomReady  = $this->positiveInt($timing['dom_ready'] ?? null);
        $log->Load      = $this->positiveInt($timing['load'] ?? null);
        $log->TransferSize = $this->positiveInt($timing['transfer'] ?? null);

        // Context
        $ctx                = $data['ctx'] ?? [];
        $log->IsMobile      = (bool) ($ctx['ua_mobile'] ?? false);
        $log->ConnectionType= $this->sanitiseString($ctx['conn'] ?? '');
        $log->ViewportWidth = $this->positiveInt($ctx['vw'] ?? null);

        // Errors + broken resources (sanitised, capped)
        $errors = array_slice((array) ($data['errors'] ?? []), 0, (int) self::config()->get('max_errors_stored'));
        $broken = array_slice((array) ($data['broken'] ?? []), 0, (int) self::config()->get('max_broken_stored'));

        $log->ErrorsJson = $errors ? json_encode($this->sanitiseErrors($errors))  : null;
        $log->BrokenJson = $broken ? json_encode($this->sanitiseBroken($broken))  : null;

        // Join to AuditPage
        $page = AuditPage::get()
            ->filter(['URL' => $url, 'AuditSessionID' => $session->ID])
            ->first();

        if (!$page) {
            // URL not yet crawled — create stub; spider will pick it up next tick
            $page                 = AuditPage::create();
            $page->URL            = $url;
            $page->AuditSessionID = $session->ID;
            $page->IsCrawled      = false;
            $page->IsFromSitemap  = false;
            $page->write();
        }

        $log->AuditPageID = $page->ID;
        $log->write();

        // ── Push p75 CWV to AuditPage once we have enough samples ─────────────
        $this->maybeUpdatePageCWV($page, $session->ID);

        return $this->respond(204);
    }

    // ── p75 aggregation ───────────────────────────────────────────────────────

    /**
     * Compute p75 for LCP, CLS, INP across all beacons for this page
     * and write back to AuditPage once min_samples threshold is reached.
     *
     * p75 matches Google's CWV measurement methodology.
     */
    private function maybeUpdatePageCWV(AuditPage $page, int $sessionId): void
    {
        $minSamples = (int) self::config()->get('min_samples');

        $beacons = BeaconLog::get()
            ->filter('AuditPageID', $page->ID);

        $count = $beacons->count();

        if ($count < $minSamples) {
            return; // Not enough data yet
        }

        // Collect non-null values
        $lcpVals = $beacons->exclude('LCP', 0)->column('LCP');
        $clsVals = $beacons->exclude('CLS', null)->column('CLS');
        $inpVals = $beacons->exclude('INP', 0)->column('INP');

        if ($lcpVals) {
            $page->CWV_LCP      = $this->p75(array_map('intval', $lcpVals));
            $page->CWV_LCP_Rating = $this->lcpRating($page->CWV_LCP);
        }
        if ($clsVals) {
            $page->CWV_CLS      = $this->p75(array_map('floatval', $clsVals));
            $page->CWV_CLS_Rating = $this->clsRating($page->CWV_CLS);
        }
        if ($inpVals) {
            $page->CWV_INP      = $this->p75(array_map('intval', $inpVals));
            $page->CWV_INP_Rating = $this->inpRating($page->CWV_INP);
        }

        $page->CWV_SampleCount = $count;
        $page->write();
    }

    // ── Maths ─────────────────────────────────────────────────────────────────

    private function p75(array $values): int|float
    {
        if (!$values) return 0;
        sort($values);
        $idx = (int) ceil(count($values) * 0.75) - 1;
        return $values[max(0, $idx)];
    }

    // ── Rating helpers ────────────────────────────────────────────────────────

    private function lcpRating(int $v): string
    {
        return match(true) {
            $v <= 2500 => 'good',
            $v <= 4000 => 'needs-improvement',
            default    => 'poor',
        };
    }

    private function clsRating(float $v): string
    {
        return match(true) {
            $v <= 0.1  => 'good',
            $v <= 0.25 => 'needs-improvement',
            default    => 'poor',
        };
    }

    private function inpRating(int $v): string
    {
        return match(true) {
            $v <= 200  => 'good',
            $v <= 500  => 'needs-improvement',
            default    => 'poor',
        };
    }

    // ── Sanitisation ──────────────────────────────────────────────────────────

    private function sanitiseString(mixed $v): string
    {
        return substr(strip_tags((string) ($v ?? '')), 0, 128);
    }

    private function positiveInt(mixed $v): int
    {
        $i = (int) ($v ?? 0);
        return $i > 0 ? $i : 0;
    }

    private function boundedFloat(mixed $v, float $min, float $max): float
    {
        $f = (float) ($v ?? 0);
        return max($min, min($max, $f));
    }

    private function sanitiseErrors(array $errors): array
    {
        return array_map(function ($e) {
            return [
                'msg'  => substr(strip_tags((string) ($e['msg']  ?? '')), 0, 200),
                'src'  => substr(strip_tags((string) ($e['src']  ?? '')), 0, 300),
                'line' => (int) ($e['line'] ?? 0),
                'col'  => (int) ($e['col']  ?? 0),
            ];
        }, $errors);
    }

    private function sanitiseBroken(array $broken): array
    {
        $allowed = ['img', 'script', 'link', 'source', 'audio', 'video'];
        return array_filter(array_map(function ($b) use ($allowed) {
            $tag = strtolower((string) ($b['tag'] ?? ''));
            if (!in_array($tag, $allowed, true)) return null;
            return [
                'tag' => $tag,
                'src' => substr(strip_tags((string) ($b['src'] ?? '')), 0, 300),
            ];
        }, $broken));
    }

    private function normaliseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts) return $url;

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host']   ?? '');
        $path   = $parts['path'] ?? '/';
        $query  = isset($parts['query']) ? '?' . $parts['query'] : '';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return "{$scheme}://{$host}{$path}{$query}";
    }

    // ── Response helpers ──────────────────────────────────────────────────────

    private function respond(int $code): HTTPResponse
    {
        $r = HTTPResponse::create();
        $r->setStatusCode($code);
        $r->addHeader('Access-Control-Allow-Origin', $this->requestOrigin ?? '*');
        $r->addHeader('Access-Control-Allow-Credentials', 'true');
        $r->addHeader('Vary', 'Origin');
        return $r;
    }

    private function corsResponse(): HTTPResponse
    {
        $r = HTTPResponse::create();
        $r->setStatusCode(204);
        $r->addHeader('Access-Control-Allow-Origin',  $this->requestOrigin ?? '*');
        $r->addHeader('Access-Control-Allow-Credentials', 'true');
        $r->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $r->addHeader('Access-Control-Allow-Headers', 'Content-Type');
        $r->addHeader('Access-Control-Max-Age',       '600');
        $r->addHeader('Vary', 'Origin');
        return $r;
    }
}
