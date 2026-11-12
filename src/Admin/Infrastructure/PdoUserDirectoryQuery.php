<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Application\UserDirectoryFilter;
use App\Admin\Application\UserDirectoryQuery;
use App\Admin\Application\UserListItem;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserStatus;
use DateTimeImmutable;
use PDO;

/**
 * Users tool queries. Search is ILIKE on name and email (pg_trgm GIN indexes); the list is
 * ordered by `created_at DESC, id` so pages are stable.
 */
final class PdoUserDirectoryQuery implements UserDirectoryQuery
{
    public function __construct(private readonly PDO $pdo) {}

    public function search(UserDirectoryFilter $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filter);

        $count = $this->pdo->prepare("SELECT count(*) FROM users u LEFT JOIN organizations o ON o.owner_user_id = u.id {$where}");
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, o.business_name
               FROM users u
               LEFT JOIN organizations o ON o.owner_user_id = u.id
               {$where}
              ORDER BY u.created_at DESC, u.id
              LIMIT :limit OFFSET :offset",
        );
        $this->bind($stmt, $params);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = new UserListItem(
                (string) $row['id'],
                (string) $row['name'],
                (string) $row['email'],
                Role::from((string) $row['role']),
                UserStatus::from((string) $row['status']),
                is_string($row['business_name']) ? $row['business_name'] : null,
                new DateTimeImmutable((string) $row['created_at']),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    public function organizations(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, business_name FROM organizations ORDER BY lower(business_name), id') ?: [] as $row) {
            $out[] = ['id' => (string) $row['id'], 'name' => (string) $row['business_name']];
        }

        return $out;
    }

    /** @return array{string, array<string, string>} */
    private function conditions(UserDirectoryFilter $filter): array
    {
        $parts = [];
        $params = [];
        $search = trim($filter->search);
        if ($search !== '') {
            $parts[] = '(u.name ILIKE :search OR u.email ILIKE :search)';
            $params['search'] = '%' . addcslashes($search, '\\%_') . '%';
        }
        if ($filter->role !== null) {
            $parts[] = 'u.role = :role';
            $params['role'] = $filter->role->value;
        }
        if ($filter->status !== null) {
            $parts[] = 'u.status = :status';
            $params['status'] = $filter->status->value;
        }
        if ($filter->organizationId !== null) {
            $parts[] = 'o.id = :org';
            $params['org'] = $filter->organizationId;
        }

        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string, string> $params */
    private function bind(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
    }
}
