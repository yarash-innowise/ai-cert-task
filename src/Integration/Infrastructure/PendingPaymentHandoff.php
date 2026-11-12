<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\HandoffResult;
use App\Integration\Application\PurchaseHandoff;
use Psr\Log\LoggerInterface;

/** Stand-in until Epic-05 exists: the hand-off is recorded in the log and never reports success. */
final class PendingPaymentHandoff implements PurchaseHandoff
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handOff(string $approvalRequestId, string $parentUserId, string $playerProfileId, string $eventRef, int $tokens, ?int $amountMinor = null, ?string $currency = null): HandoffResult
    {
        $this->logger->info('PurchaseHandoff stub: payment not available, hand-off left pending (Epic-05 not installed)', [
            'approval' => $approvalRequestId,
            'tokens' => $tokens,
        ]);

        return HandoffResult::Pending;
    }
}
