<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

interface ShareLinkRepository
{
    /** Tenant method: the organization's active player link, if any. */
    public function findActivePlayerLink(OrganizationId $organization): ?ShareLink;

    /**
     * @throws ActiveLinkExists when the organization already has an active player link (race)
     */
    public function createPlayerLink(OrganizationId $organization, string $code, DateTimeImmutable $at): ShareLink;

    /** @return bool false when the organization has no active player link */
    public function deactivatePlayerLink(OrganizationId $organization, DateTimeImmutable $at): bool;

    /**
     * Public resolution by the unguessable code (the one non-tenant lookup): only an ACTIVE link
     * whose organization and owning Trainer are both active, otherwise null.
     */
    public function findUsableByCode(string $code): ?ResolvedShareLink;

    /** Counts and records one use of the organization's link (AC-23). */
    public function recordUse(OrganizationId $organization, string $shareLinkId, ?string $userId, ?string $playerProfileId, string $outcome, DateTimeImmutable $at): void;
}
