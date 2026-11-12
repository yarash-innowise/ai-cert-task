<?php

declare(strict_types=1);

use App\Approval\Application\HandOffApproved;
use App\Approval\Domain\ApprovalJobs;
use App\Approval\Domain\ApprovalRepository;
use App\Approval\Infrastructure\ExpireApprovalsTask;
use App\Approval\Infrastructure\PdoApprovalRepository;

use function DI\add;
use function DI\autowire;

/** Approval module bindings. */
return [
    ApprovalRepository::class => autowire(PdoApprovalRepository::class),
    'job.handlers' => add([[ApprovalJobs::HANDOFF, HandOffApproved::class]]),
    'scheduler.tasks' => add([ExpireApprovalsTask::class]),
];
