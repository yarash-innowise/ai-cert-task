<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\Policy\ApprovalRequestPolicy;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;

/** The child login's own requests with their current status (AC-40: the child sees the status change). */
final class ListMyRequests
{
    public function __construct(private readonly ApprovalRepository $requests, private readonly ApprovalRequestPolicy $policy) {}

    /** @return list<ApprovalRequest> */
    public function execute(CurrentUser $actor): array
    {
        if (!$this->policy->canRequest($actor)) {
            throw new Forbidden();
        }

        return $this->requests->forChild($actor->id);
    }
}
