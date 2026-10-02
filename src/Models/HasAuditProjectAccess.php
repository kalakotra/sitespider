<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Models;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

trait HasAuditProjectAccess
{
    protected function getAuditProjectForAccess(): ?AuditProject
    {
        if ($this instanceof AuditProject) {
            return $this;
        }
        if ($this instanceof AuditSession) {
            $project = $this->AuditProject();
            return $project->exists() ? $project : null;
        }
        if ($this instanceof AuditPage) {
            $session = $this->AuditSession();
            return $session->exists() ? $session->AuditProject() : null;
        }
        if ($this instanceof AuditTask) {
            $page = $this->AuditPage();
            $session = $page->exists() ? $page->AuditSession() : null;
            return $session && $session->exists() ? $session->AuditProject() : null;
        }
        if ($this instanceof AuditFinding) {
            $project = $this->AuditProject();
            return $project->exists() ? $project : null;
        }
        if ($this instanceof BeaconLog) {
            $page = $this->AuditPage();
            $session = $page->exists() ? $page->AuditSession() : null;
            return $session && $session->exists() ? $session->AuditProject() : null;
        }
        if ($this instanceof PageLink) {
            $page = $this->SourcePage();
            $session = $page->exists() ? $page->AuditSession() : null;
            return $session && $session->exists() ? $session->AuditProject() : null;
        }

        return null;
    }

    protected function isAuditProjectOwner(?Member $member): bool
    {
        if (!$member || !$member->exists()) {
            return false;
        }
        $project = $this->getAuditProjectForAccess();
        return $project !== null && (int) $project->OwnerID === (int) $member->ID;
    }

    public function canView($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        return Permission::checkMember($member, 'ADMIN') || $this->isAuditProjectOwner($member);
    }

    public function canEdit($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        return Permission::checkMember($member, 'ADMIN') || $this->isAuditProjectOwner($member);
    }

    public function canDelete($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        return Permission::checkMember($member, 'ADMIN');
    }

    public function canCreate($member = null, $context = []): bool
    {
        $member ??= Security::getCurrentUser();
        return Permission::checkMember($member, 'ADMIN');
    }
}