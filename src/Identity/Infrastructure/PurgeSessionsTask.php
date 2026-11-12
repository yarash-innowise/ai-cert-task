<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\SessionRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;

final class PurgeSessionsTask implements ScheduledTask
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly Clock $clock,
    ) {}

    public function name(): string
    {
        return 'sessions:purge';
    }

    public function intervalSeconds(): int
    {
        return 3600;
    }

    public function run(): void
    {
        $this->sessions->purgeExpired($this->clock->now());
    }
}
