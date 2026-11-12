<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Domain\RegistrationPrefill;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;

final class PdoRegistrationPrefillRepository extends TenantRepository implements RegistrationPrefillRepository
{
    /** @param array<string, string> $fields */
    public function add(OrganizationId $organization, string $tokenHash, array $fields, DateTimeImmutable $expiresAt, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_prefills (organization_id, token_hash, data, created_at, expires_at) VALUES (:organization_id, :hash, :data, :at, :expires)',
        );
        $stmt->execute($this->scopeParams($organization, [
            'hash' => $tokenHash,
            'data' => json_encode($fields, JSON_THROW_ON_ERROR),
            'at' => $at->format('Y-m-d H:i:s.uP'),
            'expires' => $expiresAt->format('Y-m-d H:i:s.uP'),
        ]));
    }

    public function findUsableByHash(string $tokenHash, DateTimeImmutable $now): ?RegistrationPrefill
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.organization_id, p.data, p.expires_at, o.business_name
             FROM registration_prefills p
             JOIN organizations o ON o.id = p.organization_id
             JOIN users u ON u.id = o.owner_user_id
             WHERE p.token_hash = :hash AND p.consumed_at IS NULL AND p.expires_at > :now
               AND o.status = 'active' AND u.status = 'active'",
        );
        $stmt->execute(['hash' => $tokenHash, 'now' => $now->format('Y-m-d H:i:s.uP')]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        $fields = [];
        foreach (is_array($data) ? $data : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $fields[$name] = $value;
            }
        }

        return new RegistrationPrefill((string) $row['id'], (string) $row['organization_id'], (string) $row['business_name'], $fields, new DateTimeImmutable((string) $row['expires_at']));
    }

    public function consume(OrganizationId $organization, string $id, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare('UPDATE registration_prefills SET consumed_at = :at WHERE id = :id AND consumed_at IS NULL AND ' . $this->scope());
        $stmt->execute($this->scopeParams($organization, ['id' => $id, 'at' => $at->format('Y-m-d H:i:s.uP')]));

        return $stmt->rowCount() === 1;
    }
}
