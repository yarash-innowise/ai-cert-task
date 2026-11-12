<?php

declare(strict_types=1);

namespace App\Availability\Domain;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

/** Tenant repository (D8): every method names the organization; a record of another organization is "not found". */
interface CoachAssignments
{
    /** Records why the Trainer assigned despite the Coach's schedule; returns the override id. */
    public function recordOverride(OrganizationId $organization, string $eventRef, string $coachUserId, string $reason, string $overriddenBy): string;

    /** @throws AssignmentAlreadyExists the Coach already has this event */
    public function create(
        OrganizationId $organization,
        string $eventRef,
        DateTimeImmutable $eventDate,
        TimeRange $window,
        string $coachUserId,
        string $assignedBy,
        ?string $overrideId,
    ): CoachAssignment;

    public function find(OrganizationId $organization, string $id): ?CoachAssignment;

    /** @return list<CoachAssignment> newest event first */
    public function forOrganization(OrganizationId $organization): array;

    /** @return list<CoachAssignment> the Coach's own assignments in the organization, soonest event first */
    public function forCoach(OrganizationId $organization, string $coachUserId): array;

    public function respond(OrganizationId $organization, string $id, AssignmentStatus $status, ?string $note, DateTimeImmutable $at): void;
}
