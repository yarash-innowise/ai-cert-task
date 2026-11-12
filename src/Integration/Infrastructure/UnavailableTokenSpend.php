<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\SpendResult;
use App\Integration\Application\TokenSpend;
use Psr\Log\LoggerInterface;

/** Stand-in until Epic-05 exists: tokens cannot be spent. */
final class UnavailableTokenSpend implements TokenSpend
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function spend(string $payerUserId, int $tokens, string $reference): SpendResult
    {
        $this->logger->info('TokenSpend stub: tokens unavailable, nothing spent (Epic-05 not installed)', ['tokens' => $tokens, 'reference' => $reference]);

        return SpendResult::Unavailable;
    }
}
