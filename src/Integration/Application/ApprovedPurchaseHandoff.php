<?php

declare(strict_types=1);

namespace App\Integration\Application;

/**
 * The hand-off rule of AC-41 in one place: an approved purchase goes to the payment capability and
 * the child is registered only when that capability reports success.
 */
final class ApprovedPurchaseHandoff
{
    public function __construct(
        private readonly PurchaseHandoff $payments,
        private readonly EventRegistrations $registrations,
    ) {}

    /** @return HandoffResult the payment outcome; the registration happens only for Succeeded */
    public function execute(string $approvalRequestId, string $parentUserId, string $playerProfileId, string $eventRef, int $tokens, ?int $amountMinor = null, ?string $currency = null): HandoffResult
    {
        $result = $this->payments->handOff($approvalRequestId, $parentUserId, $playerProfileId, $eventRef, $tokens, $amountMinor, $currency);
        if ($result === HandoffResult::Succeeded) {
            $this->registrations->register($eventRef, $playerProfileId);
        }

        return $result;
    }
}
