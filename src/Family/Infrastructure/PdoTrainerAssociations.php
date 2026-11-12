<?php

declare(strict_types=1);

namespace App\Family\Infrastructure;

use App\Family\Domain\ContextEntry;
use App\Family\Domain\ProfileKind;
use App\Family\Domain\TrainerAssociation;
use App\Family\Domain\TrainerAssociations;
use App\Family\Domain\TrainerOption;
use DateTimeImmutable;
use PDO;

final class PdoTrainerAssociations implements TrainerAssociations
{
    public function __construct(private readonly PDO $pdo) {}

    public function associate(string $profileId, string $organizationId, ?string $sourceShareLinkId, DateTimeImmutable $at): bool
    {
        // ON CONFLICT on the partial unique index of active pairs keeps this race-free.
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_trainer_associations (player_profile_id, organization_id, source_share_link_id, associated_at)
             VALUES (:profile, :org, :link, :at)
             ON CONFLICT (player_profile_id, organization_id) WHERE ended_at IS NULL DO NOTHING',
        );
        $stmt->execute(['profile' => $profileId, 'org' => $organizationId, 'link' => $sourceShareLinkId, 'at' => $at->format('Y-m-d H:i:s.uP')]);

        return $stmt->rowCount() === 1;
    }

    public function end(string $profileId, string $organizationId, string $endedByUserId, string $reason, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE player_trainer_associations SET ended_at = :at, ended_by = :by, end_reason = :reason
             WHERE player_profile_id = :profile AND organization_id = :org AND ended_at IS NULL',
        );
        $stmt->execute(['at' => $at->format('Y-m-d H:i:s.uP'), 'by' => $endedByUserId, 'reason' => $reason, 'profile' => $profileId, 'org' => $organizationId]);

        return $stmt->rowCount() === 1;
    }

    public function endAllOfTrainer(string $trainerUserId, string $endedByUserId, string $reason, DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE player_trainer_associations a SET ended_at = :at, ended_by = :by, end_reason = :reason
              FROM organizations o
             WHERE o.id = a.organization_id AND o.owner_user_id = :trainer AND a.ended_at IS NULL
         RETURNING a.player_profile_id, a.organization_id',
        );
        $stmt->execute(['at' => $at->format('Y-m-d H:i:s.uP'), 'by' => $endedByUserId, 'reason' => $reason, 'trainer' => $trainerUserId]);

        $ended = [];
        foreach ($stmt->fetchAll() as $row) {
            $ended[] = ['profile_id' => (string) $row['player_profile_id'], 'organization_id' => (string) $row['organization_id']];
        }

        return $ended;
    }

    public function endAllOfGuardianChildren(string $guardianUserId, string $endedByUserId, string $reason, DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE player_trainer_associations a SET ended_at = :at, ended_by = :by, end_reason = :reason
              FROM player_profiles p
             WHERE p.id = a.player_profile_id AND p.owner_user_id = :guardian AND p.kind = 'child' AND a.ended_at IS NULL
         RETURNING a.player_profile_id, a.organization_id",
        );
        $stmt->execute(['at' => $at->format('Y-m-d H:i:s.uP'), 'by' => $endedByUserId, 'reason' => $reason, 'guardian' => $guardianUserId]);

        $ended = [];
        foreach ($stmt->fetchAll() as $row) {
            $ended[] = ['profile_id' => (string) $row['player_profile_id'], 'organization_id' => (string) $row['organization_id']];
        }

        return $ended;
    }

    public function activeForProfiles(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($profileIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT a.player_profile_id, a.organization_id, a.associated_at, o.business_name
             FROM player_trainer_associations a JOIN organizations o ON o.id = a.organization_id
             WHERE a.ended_at IS NULL AND a.player_profile_id IN ({$placeholders})
             ORDER BY a.associated_at, a.id",
        );
        $stmt->execute($profileIds);
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(string) $row['player_profile_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    public function activeAssociation(string $profileId, string $organizationId): ?TrainerAssociation
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.player_profile_id, a.organization_id, a.associated_at, o.business_name
             FROM player_trainer_associations a JOIN organizations o ON o.id = a.organization_id
             WHERE a.ended_at IS NULL AND a.player_profile_id = :profile AND a.organization_id = :org',
        );
        $stmt->execute(['profile' => $profileId, 'org' => $organizationId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function contextsOf(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id AS profile_id, p.kind, p.first_name, p.last_name, o.id AS organization_id, o.business_name
             FROM player_trainer_associations a
             JOIN player_profiles p ON p.id = a.player_profile_id AND p.deleted_at IS NULL
             JOIN organizations o ON o.id = a.organization_id AND o.status = 'active'
             JOIN users u ON u.id = :user
             WHERE a.ended_at IS NULL
               AND ((p.owner_user_id = u.id AND u.account_kind = 'standard') OR p.login_user_id = u.id)
             ORDER BY (p.kind = 'self') DESC, o.business_name, p.first_name, p.id, o.id",
        );
        $stmt->execute(['user' => $userId]);

        return array_values(array_map(static fn(array $r): ContextEntry => new ContextEntry(
            (string) $r['profile_id'],
            ProfileKind::from((string) $r['kind']),
            trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
            (string) $r['organization_id'],
            (string) $r['business_name'],
        ), $stmt->fetchAll()));
    }

    public function trainersOfOwner(string $ownerUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.business_name
             FROM organizations o
             WHERE o.status = 'active' AND EXISTS (
                 SELECT 1 FROM player_trainer_associations a JOIN player_profiles p ON p.id = a.player_profile_id
                 WHERE a.organization_id = o.id AND a.ended_at IS NULL AND p.owner_user_id = :owner AND p.deleted_at IS NULL
             )
             ORDER BY o.business_name, o.id",
        );
        $stmt->execute(['owner' => $ownerUserId]);

        return array_values(array_map(static fn(array $r): TrainerOption => new TrainerOption((string) $r['id'], (string) $r['business_name']), $stmt->fetchAll()));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): TrainerAssociation
    {
        return new TrainerAssociation(
            (string) $row['player_profile_id'],
            (string) $row['organization_id'],
            (string) $row['business_name'],
            new DateTimeImmutable((string) $row['associated_at']),
        );
    }
}
