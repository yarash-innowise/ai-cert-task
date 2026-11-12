<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Application\SweepImpersonations;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;

/** Scheduled task `impersonations:sweep`: closes abandoned or overdue impersonations. */
final class SweepImpersonationsTask implements ScheduledTask
{
    public function __construct(private readonly SweepImpersonations $sweep) {}

    public function name(): string
    {
        return 'impersonations:sweep';
    }

    public function intervalSeconds(): int
    {
        return 300;
    }

    public function run(): void
    {
        $this->sweep->execute();
    }
}
