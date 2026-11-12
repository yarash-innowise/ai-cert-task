<?php

declare(strict_types=1);

namespace App\Approval\Domain;

/** Outbox job topics of the approval workflow. */
final class ApprovalJobs
{
    /** An approved request is handed to the payment capability, outside any transaction (AC-41). */
    public const HANDOFF = 'approvals.handoff';
}
