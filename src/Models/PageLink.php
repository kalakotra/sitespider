<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use Kalakotra\SiteSpider\Models\HasAuditProjectAccess;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * PageLink — directed edge in the internal link graph.
 *
 *   SourcePage ──[AnchorText]──▶ TargetPage
 *
 * Every internal <a href> found during a crawl becomes one PageLink row.
 * InboundLinksCount / OutboundLinksCount on AuditPage are derived by
 * counting PageLink rows at write-time.
 *
 * @property string $AnchorText
 * @property bool   $IsNoFollow
 * @property int    $SourcePageID
 * @property int    $TargetPageID
 */
class PageLink extends DataObject
{
    use HasAuditProjectAccess;

    public function canEdit($member = null): bool
    {
        return Permission::checkMember($member ?? Security::getCurrentUser(), 'ADMIN');
    }

    private static string $table_name = 'KSS_PageLink';

    private static string $singular_name = 'Page Link';
    private static string $plural_name   = 'Page Links';

    private static array $db = [
        'AnchorText' => 'Varchar(512)',
        'IsNoFollow' => 'Boolean',
    ];

    private static array $has_one = [
        'SourcePage' => AuditPage::class,
        'TargetPage' => AuditPage::class,
    ];

    private static array $indexes = [
        'UniqueEdge' => [
            'type'    => 'unique',
            'columns' => ['SourcePageID', 'TargetPageID'],
        ],
        'TargetPageID' => true,
        'SourcePageID' => true,
    ];

    private static array $summary_fields = [
        'SourcePage.URL' => 'Source',
        'TargetPage.URL' => 'Target',
        'AnchorText'     => 'Anchor',
        'IsNoFollow'     => 'NoFollow',
    ];
}
