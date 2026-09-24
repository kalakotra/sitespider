<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Controllers;

use Kalakotra\SiteSpider\Models\AuditSession;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Public controller for shareable client audit reports.
 *
 * Route: GET /audit-report/{token}
 *
 * No authentication required — the token acts as the access credential.
 * Response headers prevent search engine indexing and proxy caching.
 */
class AuditReportController extends Controller
{
    private static array $url_handlers = [
        '' => 'index',
    ];

    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): HTTPResponse
    {
        $token = (string) $request->param('Token');

        if (!$token) {
            return $this->httpError(404, 'Report not found');
        }

        /** @var AuditSession|null $session */
        $session = AuditSession::get()->find('ShareToken', $token);

        if (!$session || !$session->exists()) {
            return $this->httpError(404, 'Report not found');
        }

        $crawled   = $session->Pages()->filter('IsCrawled', true);
        $ok200     = $crawled->filter('HttpStatus', 200);
        $broken    = $crawled->filter('HttpStatus', 404);
        $redirects = $crawled->filter('HttpStatus', [301, 302, 303, 307, 308]);
        $orphans   = $ok200->filter('IsOrphan', true);
        $noindex   = $ok200->filter('RobotsNoIndex', true);
        $noTitle   = $ok200->filter('TitleLength', 0);
        $noMeta    = $ok200->filter('MetaDescriptionLength', 0);
        $aiWarnings = $crawled->exclude('KeywordCannibalizationWarning', [null, '']);

        $ok200Count = $ok200->count();
        $avgMs      = $ok200Count > 0
            ? (int) round((float) $ok200->sum('ResponseTimeMs') / $ok200Count)
            : 0;

        $allPagesLimit   = 1000;
        $allCrawledCount = $crawled->count();

        $html = (string) $this->customise([
            'Session'         => $session,
            'AllPages'        => $crawled->sort('InboundLinksCount DESC')->limit($allPagesLimit),
            'AllPagesCount'   => $allCrawledCount,
            'AllPagesLimited' => $allCrawledCount > $allPagesLimit,
            'BrokenPages'     => $broken,
            'RedirectPages'   => $redirects,
            'OrphanPages'     => $orphans,
            'NoIndexPages'    => $noindex,
            'NoTitlePages'    => $noTitle,
            'NoMetaPages'     => $noMeta,
            'AIWarnings'      => $aiWarnings,
            'AvgResponseMs'   => $avgMs,
        ])->renderWith(['Kalakotra/SiteSpider/AuditReport']);

        return HTTPResponse::create($html, 200)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'no-store')
            ->addHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
