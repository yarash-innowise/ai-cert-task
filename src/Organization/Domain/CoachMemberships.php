<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

interface CoachMemberships
{
    public function activeOf(string $coachUserId): ?CoachMembership;

    /**
     * Locks the organization row FOR SHARE: a Trainer deletion (FOR UPDATE) cannot interleave with an acceptance.
     * Lock order everywhere: organization, then invitation, then memberships.
     */
    public function lockOrganization(OrganizationId $organization): void;

    /**
     * Ends the Coach's active memberships in organizations that are inactive (deactivated or deleted Trainer), so the Coach can
     * accept another Trainer's invitation (AC-59). Chosen over "not blocking" because the one-active-per-coach unique index
     * is the invariant; the trade-off is that a reactivated old Trainer no longer has this Coach and must invite them again.
     *
     * @return int memberships ended
     */
    public function endInInactiveOrganizations(string $coachUserId, DateTimeImmutable $at): int;

    /** @throws CoachAlreadyActive the Coach is already active under a Trainer (database invariant); the caller's transaction is aborted and must be rolled back */
    public function start(OrganizationId $organization, string $coachUserId, ?string $invitationId, DateTimeImmutable $at): CoachMembership;
}
