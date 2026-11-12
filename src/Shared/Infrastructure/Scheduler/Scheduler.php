<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Domain\Clock;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Runs due tasks. Each run is guarded by a session-level advisory lock so that
 * several worker processes never execute the same task at the same time.
 * Correctness never depends on tick timing: tasks are idempotent (design D22).
 */
final class Scheduler
{
    public const TICK_SECONDS = 60;

    /** @var array<string, int> task name => unix time of last run */
    private array $lastRun = [];

    /** @param iterable<ScheduledTask> $tasks */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly iterable $tasks,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<string> names of tasks that ran */
    public function tick(): array
    {
        $ran = [];
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->tasks as $task) {
            $name = $task->name();
            if (isset($this->lastRun[$name]) && $now - $this->lastRun[$name] < $task->intervalSeconds()) {
                continue;
            }

            $lock = $this->pdo->prepare('SELECT pg_try_advisory_lock(hashtext(:name))');
            $lock->execute(['name' => 'scheduler:' . $name]);
            if (!filter_var($lock->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $this->lastRun[$name] = $now;
            try {
                $task->run();
                $ran[] = $name;
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled task failed', ['task' => $name, 'error' => $e->getMessage()]);
            } finally {
                $unlock = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtext(:name))');
                $unlock->execute(['name' => 'scheduler:' . $name]);
            }
        }

        return $ran;
    }
}
