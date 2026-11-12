<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Shared\Infrastructure\Audit\PdoAuditLogger;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

#[Group('AC-87')]
final class AuditLogAppendOnlyTest extends IntegrationTestCase
{
    private function userId(): string
    {
        return (string) $this->scalar(
            "INSERT INTO users (email, name, role) VALUES ('audit.actor@example.com', 'Actor', 'super_admin') RETURNING id",
        );
    }

    /** @return array{0: PDO, 1: int} a connection as app_rw inside a transaction, plus a fresh row id */
    private function appRwWithRow(): array
    {
        $pdo = self::connectAsAppRw();
        $pdo->beginTransaction();
        (new PdoAuditLogger($pdo))->log('trainer.created', null, 'user', 'u-1', null, ['business_name' => 'Acme']);
        $stmt = $pdo->prepare('SELECT max(id) FROM audit_log');
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return [$pdo, (int) $id];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testLoggerWritesRowWithActorAndPayload(): void
    {
        $actor = $this->userId();
        (new PdoAuditLogger($this->pdo))->log('trainer.created', $actor, 'user', 'subject-1', null, ['business_name' => 'Acme'], null, '203.0.113.9');
        $row = $this->row("SELECT * FROM audit_log WHERE subject_id = 'subject-1'");
        self::assertIsArray($row);
        self::assertSame($actor, $row['actor_user_id']);
        self::assertSame('trainer.created', $row['action']);
        self::assertSame(['business_name' => 'Acme'], json_decode((string) $row['payload'], true));
        self::assertNotEmpty($row['occurred_at']);
    }

    public function testUpdateAsAppRwIsRejected(): void
    {
        [$pdo, $id] = $this->appRwWithRow();
        try {
            $this->expectException(PDOException::class);
            $pdo->exec("UPDATE audit_log SET action = 'x' WHERE id = {$id}");
        } finally {
            $pdo->rollBack();
        }
    }

    public function testDeleteAsAppRwIsRejected(): void
    {
        [$pdo, $id] = $this->appRwWithRow();
        try {
            $this->expectException(PDOException::class);
            $pdo->exec("DELETE FROM audit_log WHERE id = {$id}");
        } finally {
            $pdo->rollBack();
        }
    }

    public function testTruncateAsAppRwIsRejected(): void
    {
        [$pdo] = $this->appRwWithRow();
        try {
            $this->expectException(PDOException::class);
            $pdo->exec('TRUNCATE audit_log');
        } finally {
            $pdo->rollBack();
        }
    }

    public function testTriggerBlocksEvenTheOwnerRole(): void
    {
        // The owner connection (migrations only) is the one the grants do not restrict: the trigger is the second layer.
        $owner = self::connectAsOwner();
        $owner->beginTransaction();
        try {
            (new PdoAuditLogger($owner))->log('x.y', null, 'user', 'owner-test');
            foreach (["UPDATE audit_log SET action = 'z'", 'DELETE FROM audit_log', 'TRUNCATE audit_log'] as $sql) {
                $owner->exec('SAVEPOINT s');
                try {
                    $owner->exec($sql);
                    self::fail("Expected failure for: {$sql}");
                } catch (PDOException $e) {
                    self::assertStringContainsString('append-only', $e->getMessage(), $sql);
                } finally {
                    $owner->exec('ROLLBACK TO SAVEPOINT s');
                }
            }
        } finally {
            $owner->rollBack();
        }
    }

    public function testTheApplicationRoleHasOnlyInsertAndSelectOnTheAuditLog(): void
    {
        foreach (['INSERT' => true, 'SELECT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false, 'REFERENCES' => false, 'TRIGGER' => false] as $privilege => $expected) {
            self::assertSame($expected, (bool) $this->scalar("SELECT has_table_privilege('app_rw', 'audit_log', '{$privilege}')"), $privilege);
        }
        self::assertSame('app_rw', $this->scalar('SELECT current_user'), 'tests run as the application role');
        self::assertFalse((bool) $this->scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user'));
    }
}
