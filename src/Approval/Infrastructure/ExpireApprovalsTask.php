<?php

declare(strict_types=1);

namespace App\Approval\Infrastructure;

use App\Approval\Application\ExpireApprovals;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;

/** Scheduled task `approvals:expire`, every minute (design C14); the use case is idempotent. */
final class ExpireApprovalsTask implements ScheduledTask
{
    public function __construct(private readonly ExpireApprovals $expire) {}

    public function name(): string
    {
        return 'approvals:expire';
    }

    public function intervalSeconds(): int
    {
        return 60;
    }

    public function run(): void
    {
        $this->expire->execute();
    }
}
