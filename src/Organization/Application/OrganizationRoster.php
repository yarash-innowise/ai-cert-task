<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

/**
 * Read model of one organization's people. Every method takes the OrganizationId (D8); an id that is
 * not a member of that organization is "not found", exactly like an id that does not exist (AC-10).
 * Members are listed by their ACTIVE association or membership; users who were deactivated keep
 * their place and are flagged inactive instead of disappearing (AC-74).
 */
interface OrganizationRoster
{
    /** @return list<RosterPlayer> */
    public function players(OrganizationId $organization, DateTimeImmutable $today): array;

    public function player(OrganizationId $organization, string $profileId, DateTimeImmutable $today): ?RosterPlayer;

    /** @return list<RosterCoach> */
    public function coaches(OrganizationId $organization): array;

    public function coach(OrganizationId $organization, string $coachUserId): ?RosterCoach;
}
