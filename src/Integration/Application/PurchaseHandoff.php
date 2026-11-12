<?php

declare(strict_types=1);

namespace App\Integration\Application;

/**
 * Port to payments (Epic-05): an approved purchase is handed over; this epic defines the hand-off,
 * not the payment (AC-41). The child is registered only on {@see HandoffResult::Succeeded}.
 * Epic-01 binding: PendingPaymentHandoff.
 */
interface PurchaseHandoff
{
    /**
     * @param string $approvalRequestId the approved request (idempotency reference)
     * @param int $tokens tokens the purchase costs, 0 for a free event or a USD purchase
     * @param int|null $amountMinor the USD amount (minor units) of a USD purchase, as the child's request recorded it
     * @param string|null $currency the currency of $amountMinor
     */
    public function handOff(string $approvalRequestId, string $parentUserId, string $playerProfileId, string $eventRef, int $tokens, ?int $amountMinor = null, ?string $currency = null): HandoffResult;
}
