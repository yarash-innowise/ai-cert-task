<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

interface ScheduledTask
{
    /** Unique, stable name; also the advisory-lock key. */
    public function name(): string;

    public function intervalSeconds(): int;

    public function run(): void;
}
