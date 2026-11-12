<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Console\Command;
use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Scheduler\Scheduler;

/**
 * Long-running process: drains the outbox and ticks the scheduler every 60 s.
 * `worker --once` runs a single pass (used by tests and manual checks).
 */
final class WorkerCommand implements Command
{
    private bool $running = true;

    public function __construct(
        private readonly OutboxWorker $outbox,
        private readonly Scheduler $scheduler,
    ) {}

    public function name(): string
    {
        return 'worker';
    }

    public function description(): string
    {
        return 'Process the outbox and run scheduled tasks';
    }

    public function run(array $args): int
    {
        $once = in_array('--once', $args, true);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->running = false;
            });
        }

        $nextTick = 0;
        do {
            $handled = $this->outbox->runOnce();
            if (time() >= $nextTick) {
                $this->scheduler->tick();
                $nextTick = time() + Scheduler::TICK_SECONDS;
            }
            if ($once) {
                break;
            }
            if ($handled === 0) {
                sleep(1);
            }
        } while ($this->running);

        return 0;
    }
}
