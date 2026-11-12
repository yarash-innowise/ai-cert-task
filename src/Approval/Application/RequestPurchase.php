<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\PaymentType;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\RequestContext;

/**
 * A child login starts a purchase paid in USD (AC-38): the purchase is NOT completed; a request in
 * "Pending Parent Approval" is created for the parent. Token payments go through RequestTokenSpend.
 */
final class RequestPurchase
{
    public function __construct(private readonly OpenApprovalRequest $open) {}

    /** The event is unknown or has no price: refused with a ValidationFailed from {@see OpenApprovalRequest} (the amount is the catalog's, never the child's). */
    public function execute(CurrentUser $actor, RequestContext $context, NewRequestInput $input, ?string $ip = null): ApprovalRequest
    {
        return $this->open->execute($actor, $context, ApprovalKind::Purchase, PaymentType::Usd, $input, $ip);
    }
}
