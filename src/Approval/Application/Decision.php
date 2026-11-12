<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalStatus;

/** What the parent can do with a request (AC-40). */
enum Decision: string
{
    case Approve = 'approve';
    case Deny = 'deny';
    case AskForInfo = 'info';

    public function status(): ApprovalStatus
    {
        return match ($this) {
            self::Approve => ApprovalStatus::Approved,
            self::Deny => ApprovalStatus::Denied,
            self::AskForInfo => ApprovalStatus::InfoRequested,
        };
    }
}
