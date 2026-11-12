<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdo;

use PDO;

/**
 * Runs a callback atomically. Nested calls (or calls inside an externally opened
 * transaction) use savepoints so an inner failure rolls back only the inner work.
 */
final class TransactionManager
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $useSavepoint = $this->depth > 0 || $this->pdo->inTransaction();
        $name = 'sp_' . $this->depth;

        if ($useSavepoint) {
            $this->pdo->exec('SAVEPOINT ' . $name);
        } else {
            $this->pdo->beginTransaction();
        }
        ++$this->depth;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            --$this->depth;
            if ($useSavepoint) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
            } else {
                $this->rollBackOuter();
            }
            throw $e;
        }

        --$this->depth;
        if ($useSavepoint) {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
        } else {
            $this->pdo->commit();
        }

        return $result;
    }

    private function rollBackOuter(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
