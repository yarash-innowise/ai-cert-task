<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\RsvpCancellations;
use Psr\Log\LoggerInterface;

/** Stand-in until Epic-02 exists: cancels nothing and says so. */
final class NoopRsvpCancellations implements RsvpCancellations
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function cancel(string $eventRef, string $playerProfileId): bool
    {
        $this->logger->info('RsvpCancellations stub: cancellation not performed (Epic-02 not installed)', ['event' => $eventRef, 'profile' => $playerProfileId]);

        return false;
    }
}
