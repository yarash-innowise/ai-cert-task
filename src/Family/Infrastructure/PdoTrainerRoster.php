<?php

declare(strict_types=1);

namespace App\Family\Infrastructure;

use App\Family\Application\TrainerRoster;
use App\Family\Domain\Gender;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\ProfileKind;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;

final class PdoTrainerRoster extends TenantRepository implements TrainerRoster
{
    public function forOrganization(OrganizationId $organization): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM player_trainer_associations a
             JOIN player_profiles p ON p.id = a.player_profile_id
             WHERE ' . $this->scope('a') . ' AND a.ended_at IS NULL AND p.deleted_at IS NULL
             ORDER BY p.first_name, p.last_name, p.id',
        );
        $stmt->execute($this->scopeParams($organization));

        return array_values(array_map(static fn(array $row): PlayerProfile => new PlayerProfile(
            (string) $row['id'],
            (string) $row['owner_user_id'],
            ProfileKind::from((string) $row['kind']),
            is_string($row['login_user_id']) ? $row['login_user_id'] : null,
            (string) $row['first_name'],
            (string) $row['last_name'],
            is_string($row['birth_date']) ? new DateTimeImmutable($row['birth_date']) : null,
            is_string($row['gender']) ? Gender::from($row['gender']) : null,
            is_string($row['school']) ? $row['school'] : null,
            (bool) $row['token_spend_without_approval'],
        ), $stmt->fetchAll()));
    }
}
