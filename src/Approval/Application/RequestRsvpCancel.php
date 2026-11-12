<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\PaymentType;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\RequestContext;

/** A child login's RSVP cancellation needs the parent's approval too (AC-48, Q-01.09). */
final class RequestRsvpCancel
{
    public function __construct(private readonly OpenApprovalRequest $open) {}

    public function execute(CurrentUser $actor, RequestContext $context, NewRequestInput $input, ?string $ip = null): ApprovalRequest
    {
        return $this->open->execute($actor, $context, ApprovalKind::RsvpCancel, PaymentType::None, new NewRequestInput($input->eventRef, $input->eventTitle, 0, 'USD', $input->childNote), $ip);
    }
}
