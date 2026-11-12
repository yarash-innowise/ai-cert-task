<?php

declare(strict_types=1);

namespace App\Family\Domain;

use DateTimeImmutable;

interface TrainerAssociations
{
    /**
     * Starts an association (history rows of earlier, ended ones are kept).
     *
     * @return bool false when an active association with this organization already exists
     */
    public function associate(string $profileId, string $organizationId, ?string $sourceShareLinkId, DateTimeImmutable $at): bool;

    /** Ends the active association (soft delete, history kept); @return bool false when there was none */
    public function end(string $profileId, string $organizationId, string $endedByUserId, string $reason, DateTimeImmutable $at): bool;

    /**
     * Ends every active association with the organization owned by the Trainer (history kept).
     *
     * @return list<array{profile_id: string, organization_id: string}> the ended associations
     */
    public function endAllOfTrainer(string $trainerUserId, string $endedByUserId, string $reason, DateTimeImmutable $at): array;

    /**
     * Ends every active association of the child profiles a guardian owns (history kept).
     *
     * @return list<array{profile_id: string, organization_id: string}> the ended associations
     */
    public function endAllOfGuardianChildren(string $guardianUserId, string $endedByUserId, string $reason, DateTimeImmutable $at): array;

    /**
     * Active associations of the given profiles, in a single query.
     *
     * @param list<string> $profileIds
     * @return array<string, list<TrainerAssociation>> by profile id, oldest first
     */
    public function activeForProfiles(array $profileIds): array;

    /**
     * Every (profile, Trainer) pair the user may act in, from active associations only: a parent or
     * adult player sees the profiles they OWN (Me first, then children), a child login only its own
     * profile. Organizations that are not active are left out.
     *
     * @return list<ContextEntry>
     */
    public function contextsOf(string $userId): array;

    public function activeAssociation(string $profileId, string $organizationId): ?TrainerAssociation;

    /**
     * Active organizations any of the owner's profiles (Me or children) trains with.
     *
     * @return list<TrainerOption>
     */
    public function trainersOfOwner(string $ownerUserId): array;
}
