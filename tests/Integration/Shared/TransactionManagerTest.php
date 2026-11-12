<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Shared\Infrastructure\Pdo\TransactionManager;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class TransactionManagerTest extends IntegrationTestCase
{
    private function rowCount(): int
    {
        return (int) $this->scalar('SELECT count(*) FROM tm_probe');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TEMP TABLE tm_probe (v int)');
    }

    #[Group('AC-88')]
    public function testCommitsOnSuccessAndReturnsResult(): void
    {
        $tm = new TransactionManager($this->pdo);
        $r = $tm->run(function (): string {
            $this->pdo->exec('INSERT INTO tm_probe VALUES (1)');

            return 'ok';
        });
        self::assertSame('ok', $r);
        self::assertSame(1, $this->rowCount());
    }

    public function testNestedRollbackOnlyUndoesInnerWork(): void
    {
        $tm = new TransactionManager($this->pdo);
        $tm->run(function () use ($tm): void {
            $this->pdo->exec('INSERT INTO tm_probe VALUES (1)');
            try {
                $tm->run(function (): void {
                    $this->pdo->exec('INSERT INTO tm_probe VALUES (2)');
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
            }
            $this->pdo->exec('INSERT INTO tm_probe VALUES (3)');
        });
        $stmt = $this->pdo->prepare('SELECT v FROM tm_probe ORDER BY v');
        $stmt->execute();
        self::assertSame([1, 3], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testOuterFailureIsRethrown(): void
    {
        $tm = new TransactionManager($this->pdo);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outer');
        $tm->run(function (): void {
            throw new RuntimeException('outer');
        });
    }

    public function testOuterRollbackUndoesEverything(): void
    {
        $tm = new TransactionManager($this->pdo);
        try {
            $tm->run(function () use ($tm): void {
                $tm->run(fn() => $this->pdo->exec('INSERT INTO tm_probe VALUES (1)'));
                throw new RuntimeException('outer');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, $this->rowCount());
    }
}
