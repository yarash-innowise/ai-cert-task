<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use PDO;

/**
 * Bulk-creates sign-in-less sample users for load and pagination checks (development and test only).
 * Emails are `seed-{n}@example.test`, so re-running adds only the missing ones.
 */
final class UserSeeder
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return int users actually added */
    public function seed(int $count): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (email, name, role, status, email_verified_at, created_at)
             SELECT 'seed-' || n || '@example.test',
                    'Seed User ' || n,
                    (ARRAY['player','player','player','coach'])[1 + n % 4],
                    (ARRAY['active','active','active','active','inactive'])[1 + n % 5],
                    now(),
                    now() - (n || ' seconds')::interval
               FROM generate_series(1, :count) AS n
             ON CONFLICT (email) DO NOTHING",
        );
        $stmt->bindValue('count', $count, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
