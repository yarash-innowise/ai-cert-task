<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;
use PDO;

/** Keeps one day of attempts; the throttle only looks back 15 minutes. */
final class PurgeLoginAttemptsTask implements ScheduledTask
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function name(): string
    {
        return 'login-attempts:purge';
    }

    public function intervalSeconds(): int
    {
        return 3600;
    }

    public function run(): void
    {
        $this->purge();
    }

    public function purge(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :before');
        $stmt->execute(['before' => $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.uP')]);

        return $stmt->rowCount();
    }
}
