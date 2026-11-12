<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Organization\Domain\Organization;
use App\Organization\Domain\OrganizationRepository;
use App\Organization\Domain\OrganizationStatus;
use PDO;

final class PdoOrganizationRepository implements OrganizationRepository
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly PDO $pdo) {}

    public function create(string $ownerUserId, string $businessName): Organization
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizations (owner_user_id, business_name) VALUES (:owner, :name) RETURNING *',
        );
        $stmt->execute(['owner' => $ownerUserId, 'name' => $businessName]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    public function updateDetails(string $organizationId, string $businessName, ?string $details): void
    {
        $stmt = $this->pdo->prepare('UPDATE organizations SET business_name = :name, details = :details, updated_at = now() WHERE id = :id');
        $stmt->execute(['name' => $businessName, 'details' => $details, 'id' => $organizationId]);
    }

    public function detailsOf(string $organizationId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT details FROM organizations WHERE id = :id');
        $stmt->execute(['id' => $organizationId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function findByOwner(string $ownerUserId): ?Organization
    {
        return $this->fetch('owner_user_id', $ownerUserId);
    }

    public function findById(string $id): ?Organization
    {
        return $this->fetch('id', $id);
    }

    private function fetch(string $column, string $value): ?Organization
    {
        if (preg_match(self::UUID, $value) !== 1) {
            return null;
        }
        $sql = $column === 'id' ? 'SELECT * FROM organizations WHERE id = :v' : 'SELECT * FROM organizations WHERE owner_user_id = :v';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Organization
    {
        return new Organization(
            (string) $row['id'],
            (string) $row['owner_user_id'],
            (string) $row['business_name'],
            OrganizationStatus::from((string) $row['status']),
            (string) $row['timezone'],
        );
    }
}
