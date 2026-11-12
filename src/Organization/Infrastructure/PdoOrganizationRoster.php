<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Organization\Application\OrganizationRoster;
use App\Organization\Application\RosterCoach;
use App\Organization\Application\RosterPlayer;
use App\Shared\Domain\OrganizationId;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;

final class PdoOrganizationRoster extends TenantRepository implements OrganizationRoster
{
    private const PLAYER_SELECT = "SELECT p.id, p.kind, p.first_name, p.last_name, p.birth_date, a.associated_at,
            owner.name AS holder_name,
            (owner.status <> 'active' OR (login.id IS NOT NULL AND login.status <> 'active')) AS inactive
         FROM player_trainer_associations a
         JOIN player_profiles p ON p.id = a.player_profile_id
         JOIN users owner ON owner.id = p.owner_user_id
         LEFT JOIN users login ON login.id = p.login_user_id
         WHERE a.organization_id = :organization_id AND a.ended_at IS NULL AND p.deleted_at IS NULL";

    public function players(OrganizationId $organization, DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(self::PLAYER_SELECT . ' ORDER BY p.first_name, p.last_name, p.id');
        $stmt->execute($this->scopeParams($organization));

        return array_values(array_map(fn(array $row): RosterPlayer => $this->playerFrom($row, $today), $stmt->fetchAll()));
    }

    public function player(OrganizationId $organization, string $profileId, DateTimeImmutable $today): ?RosterPlayer
    {
        if (Uuid::tryFromString($profileId) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(self::PLAYER_SELECT . ' AND p.id = :profile');
        $stmt->execute($this->scopeParams($organization, ['profile' => $profileId]));
        $row = $stmt->fetch();

        return is_array($row) ? $this->playerFrom($row, $today) : null;
    }

    public function coaches(OrganizationId $organization): array
    {
        $stmt = $this->pdo->prepare($this->coachSelect() . ' ORDER BY u.name, u.id');
        $stmt->execute($this->scopeParams($organization));

        return array_values(array_map($this->coachFrom(...), $stmt->fetchAll()));
    }

    public function coach(OrganizationId $organization, string $coachUserId): ?RosterCoach
    {
        if (Uuid::tryFromString($coachUserId) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare($this->coachSelect() . ' AND u.id = :coach');
        $stmt->execute($this->scopeParams($organization, ['coach' => $coachUserId]));
        $row = $stmt->fetch();

        return is_array($row) ? $this->coachFrom($row) : null;
    }

    private function coachSelect(): string
    {
        return "SELECT u.id, u.name, u.email, m.started_at, (u.status <> 'active') AS inactive
                FROM coach_memberships m
                JOIN users u ON u.id = m.coach_user_id
                WHERE " . $this->scope('m') . " AND m.status = 'active'";
    }

    /** @param array<string, mixed> $row */
    private function playerFrom(array $row, DateTimeImmutable $today): RosterPlayer
    {
        $birth = is_string($row['birth_date']) ? new DateTimeImmutable($row['birth_date']) : null;

        return new RosterPlayer(
            (string) $row['id'],
            trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            $row['kind'] === 'child',
            $birth?->diff($today->setTime(0, 0))->y,
            (string) $row['holder_name'],
            new DateTimeImmutable((string) $row['associated_at']),
            (bool) $row['inactive'],
        );
    }

    /** @param array<string, mixed> $row */
    private function coachFrom(array $row): RosterCoach
    {
        return new RosterCoach((string) $row['id'], (string) $row['name'], (string) $row['email'], new DateTimeImmutable((string) $row['started_at']), (bool) $row['inactive']);
    }
}
