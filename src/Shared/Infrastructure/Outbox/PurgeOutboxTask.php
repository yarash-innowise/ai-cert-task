<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;
use PDO;

/** Deletes finished (done / failed) outbox rows older than a week; pending and processing rows are never touched. */
final class PurgeOutboxTask implements ScheduledTask
{
    public const RETENTION = '-7 days';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function name(): string
    {
        return 'outbox:purge';
    }

    public function intervalSeconds(): int
    {
        return 3600;
    }

    public function run(): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM outbox WHERE status IN ('done', 'failed') AND processed_at < :before",
        );
        $stmt->execute(['before' => $this->clock->now()->modify(self::RETENTION)->format('Y-m-d H:i:sP')]);
    }
}
