<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs every test inside a transaction that is rolled back afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = self::connectAsAppRw();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Connection as the restricted application role (no audit_log UPDATE/DELETE/TRUNCATE); the same
     * role the web app and worker use, so a missing grant fails a test instead of production.
     */
    protected static function connectAsAppRw(): PDO
    {
        return self::connect('app_rw', getenv('APP_RW_PASSWORD') ?: '');
    }

    /** Owner connection: only for cleanup that the restricted role may not do (committed race-test rows). */
    protected static function connectAsOwner(): PDO
    {
        return self::connect(getenv('DB_OWNER_USER') ?: 'app', getenv('DB_OWNER_PASSWORD') ?: '');
    }

    protected static function connect(?string $user = null, ?string $password = null): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '5432',
            getenv('DB_NAME_TEST') ?: 'app_test',
        );

        return new PDO($dsn, $user ?? 'app_rw', $password ?? (getenv('APP_RW_PASSWORD') ?: ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
