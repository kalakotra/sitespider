<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use SilverStripe\ORM\DataObject;

/**
 * BeaconLog — one beacon payload received from tracker.js.
 *
 * Stores raw CWV, timing, error and broken-resource data from the
 * JS thin client. Multiple beacons per URL are expected (one per page view).
 *
 * AuditPage is joined by URL at write-time in BeaconController.
 * Aggregated CWV values (p75) are pushed back to AuditPage after
 * a configurable minimum sample count.
 *
 * @property string $URL
 * @property string $Token               Tenant API token
 * @property int    $LCP                 Largest Contentful Paint (ms)
 * @property float  $CLS                 Cumulative Layout Shift
 * @property int    $INP                 Interaction to Next Paint (ms)
 * @property int    $FCP                 First Contentful Paint (ms)
 * @property int    $TTFB                Time to First Byte (ms)
 * @property int    $DomReady            DOMContentLoaded (ms)
 * @property int    $Load                window.load (ms)
 * @property int    $TransferSize        Response transfer size (bytes)
 * @property bool   $IsMobile
 * @property string $ConnectionType      4g, 3g, slow-3g, wifi...
 * @property int    $ViewportWidth
 * @property string $ErrorsJson          JSON array of JS errors
 * @property string $BrokenJson          JSON array of broken resources
 * @property int    $AuditPageID
 */
class BeaconLog extends DataObject
{
    private static string $table_name    = 'KSS_BeaconLog';
    private static string $singular_name = 'Beacon Log';
    private static string $plural_name   = 'Beacon Logs';

    private static array $db = [
        'URL'            => 'Varchar(2048)',
        'Token'          => 'Varchar(128)',

        // Core Web Vitals
        'LCP'            => 'Int',     // ms
        'CLS'            => 'Decimal(6,4)',
        'INP'            => 'Int',     // ms
        'FCP'            => 'Int',     // ms

        // Navigation timing
        'TTFB'           => 'Int',     // ms
        'DomReady'       => 'Int',     // ms
        'Load'           => 'Int',     // ms
        'TransferSize'   => 'Int',     // bytes

        // Context
        'IsMobile'       => 'Boolean',
        'ConnectionType' => 'Varchar(16)',
        'ViewportWidth'  => 'Int',

        // Raw JSON for errors and broken resources
        'ErrorsJson'     => 'Text',
        'BrokenJson'     => 'Text',
    ];

    private static array $has_one = [
        'AuditPage' => AuditPage::class,
    ];

    private static array $indexes = [
        'URL'   => true,
        'Token' => true,
    ];

    private static string $default_sort = 'Created DESC';

    private static array $summary_fields = [
        'URL'            => 'URL',
        'LCP'            => 'LCP',
        'CLS'            => 'CLS',
        'INP'            => 'INP',
        'TTFB'           => 'TTFB',
        'IsMobile'       => 'Mobile',
        'ConnectionType' => 'Conn',
        'Created'        => 'Received',
    ];

    // ── CWV rating helpers ────────────────────────────────────────────────────

    /** @return 'good'|'needs-improvement'|'poor'|null */
    public function getLCPRating(): ?string
    {
        if (!$this->LCP) return null;
        return match (true) {
            $this->LCP <= 2500 => 'good',
            $this->LCP <= 4000 => 'needs-improvement',
            default            => 'poor',
        };
    }

    /** @return 'good'|'needs-improvement'|'poor'|null */
    public function getCLSRating(): ?string
    {
        if ($this->CLS === null) return null;
        return match (true) {
            $this->CLS <= 0.1  => 'good',
            $this->CLS <= 0.25 => 'needs-improvement',
            default            => 'poor',
        };
    }

    /** @return 'good'|'needs-improvement'|'poor'|null */
    public function getINPRating(): ?string
    {
        if (!$this->INP) return null;
        return match (true) {
            $this->INP <= 200  => 'good',
            $this->INP <= 500  => 'needs-improvement',
            default            => 'poor',
        };
    }

    public function getErrors(): array
    {
        return $this->ErrorsJson ? json_decode($this->ErrorsJson, true) : [];
    }

    public function getBrokenResources(): array
    {
        return $this->BrokenJson ? json_decode($this->BrokenJson, true) : [];
    }
}
