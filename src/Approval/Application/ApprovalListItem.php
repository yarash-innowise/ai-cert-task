<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRequest;

/** A request as the parent sees it; `canDecide` is false once it is answered or past its 48 hours. */
final readonly class ApprovalListItem
{
    public function __construct(public ApprovalRequest $request, public string $childName, public bool $canDecide) {}
}
