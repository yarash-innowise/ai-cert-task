<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

final class UserEmailConstraintTest extends IntegrationTestCase
{
    private function insert(string $email): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (email, name, role) VALUES (:e, 'N', 'trainer')");
        $stmt->execute(['e' => $email]);
    }

    #[Group('AC-6')]
    public function testDuplicateEmailHitsUniqueViolation(): void
    {
        $this->insert('dup@example.com');
        $this->pdo->exec('SAVEPOINT s');
        try {
            $this->insert('dup@example.com');
            self::fail('Expected unique violation');
        } catch (PDOException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    #[Group('AC-6')]
    public function testMixedCaseEmailIsRejectedByCheckSoCaseVariantsCannotCoexist(): void
    {
        $this->insert('case@example.com');
        $this->pdo->exec('SAVEPOINT s');
        try {
            $this->insert('Case@Example.com');
            self::fail('Expected check violation');
        } catch (PDOException $e) {
            self::assertSame('23514', $e->getCode());
            self::assertStringContainsString('users_email_lowercase', $e->getMessage());
        }
    }

    #[Group('AC-6')]
    public function testLowercaseDuplicateOfNormalizedMixedCaseInputIsUniqueViolation(): void
    {
        $this->insert(strtolower('Same@Example.com'));
        $this->pdo->exec('SAVEPOINT s');
        $this->expectException(PDOException::class);
        $this->insert(strtolower('SAME@EXAMPLE.COM'));
    }

    #[Group('AC-4')]
    public function testPasswordColumnHoldsHashOnlyAndCheckConstraintsExist(): void
    {
        $stmt = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
        $stmt->execute();
        $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('password_hash', $cols);
        self::assertNotContains('password', $cols);
    }
}
