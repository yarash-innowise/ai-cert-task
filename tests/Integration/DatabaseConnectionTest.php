<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

final class DatabaseConnectionTest extends IntegrationTestCase
{
    #[Group('AC-88')]
    public function testTestDatabaseIsReachableAndRollsBack(): void
    {
        $stmt = $this->pdo->query('SELECT 1');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn());
        $this->pdo->exec('CREATE TEMP TABLE smoke (id int)');
        self::assertTrue($this->pdo->inTransaction());
    }
}
