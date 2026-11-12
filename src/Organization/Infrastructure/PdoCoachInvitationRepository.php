<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Organization\Domain\CoachInvitation;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\ResolvedCoachInvitation;
use App\Shared\Domain\Email;
use App\Shared\Domain\OrganizationId;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;

final class PdoCoachInvitationRepository extends TenantRepository implements CoachInvitationRepository
{
    private const TS = 'Y-m-d H:i:s.uP';

    public function create(
        OrganizationId $organization,
        Email $email,
        ?string $name,
        ?string $message,
        string $createdBy,
        string $tokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): CoachInvitation {
        $stmt = $this->pdo->prepare(
            'INSERT INTO coach_invitations (organization_id, email, name, message, token_hash, created_by, created_at, expires_at)
             VALUES (:organization_id, :email, :name, :message, :hash, :by, :created, :expires) RETURNING *',
        );
        $stmt->execute($this->scopeParams($organization, [
            'email' => $email->value,
            'name' => $name,
            'message' => $message,
            'hash' => $tokenHash,
            'by' => $createdBy,
            'created' => $createdAt->format(self::TS),
            'expires' => $expiresAt->format(self::TS),
        ]));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    public function findInOrganization(OrganizationId $organization, string $id, bool $lock = false): ?CoachInvitation
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM coach_invitations WHERE id = :id AND ' . $this->scope() . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute($this->scopeParams($organization, ['id' => $id]));
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listForOrganization(OrganizationId $organization): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM coach_invitations WHERE ' . $this->scope() . ' AND revoked_at IS NULL ORDER BY created_at DESC, id DESC',
        );
        $stmt->execute($this->scopeParams($organization));

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function revoke(OrganizationId $organization, string $id, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE coach_invitations SET revoked_at = :at WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL AND ' . $this->scope(),
        );
        $stmt->execute($this->scopeParams($organization, ['id' => $id, 'at' => $at->format(self::TS)]));

        return $stmt->rowCount() > 0;
    }

    public function revokePendingFor(OrganizationId $organization, Email $email, DateTimeImmutable $at): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE coach_invitations SET revoked_at = :at
             WHERE email = :email AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > :at AND ' . $this->scope(),
        );
        $stmt->execute($this->scopeParams($organization, ['email' => $email->value, 'at' => $at->format(self::TS)]));

        return $stmt->rowCount();
    }

    public function findByTokenHash(string $tokenHash, bool $lock = false): ?ResolvedCoachInvitation
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, o.business_name, (o.status = \'active\' AND u.status = \'active\') AS organization_active
             FROM coach_invitations i
             JOIN organizations o ON o.id = i.organization_id
             JOIN users u ON u.id = o.owner_user_id
             WHERE i.token_hash = :hash' . ($lock ? ' FOR UPDATE OF i' : ''),
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new ResolvedCoachInvitation($this->hydrate($row), (string) $row['business_name'], (bool) $row['organization_active']);
    }

    public function markAccepted(string $id, string $userId, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE coach_invitations SET accepted_at = :at, accepted_by = :user
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > :at',
        );
        $stmt->execute(['id' => $id, 'user' => $userId, 'at' => $at->format(self::TS)]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CoachInvitation
    {
        return new CoachInvitation(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['email'],
            is_string($row['name']) ? $row['name'] : null,
            is_string($row['message']) ? $row['message'] : null,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            is_string($row['accepted_at']) ? new DateTimeImmutable($row['accepted_at']) : null,
            is_string($row['revoked_at']) ? new DateTimeImmutable($row['revoked_at']) : null,
        );
    }
}
