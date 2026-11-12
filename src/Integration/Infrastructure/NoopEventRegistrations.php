<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\EventRegistrations;
use Psr\Log\LoggerInterface;

/** Stand-in until Epic-02 exists: registers nothing, cancels nothing, and says so. */
final class NoopEventRegistrations implements EventRegistrations
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function register(string $eventRef, string $playerProfileId): bool
    {
        $this->logger->info('EventRegistrations stub: registration not performed (Epic-02 not installed)', ['event' => $eventRef, 'profile' => $playerProfileId]);

        return false;
    }

    public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int
    {
        $this->logger->info('EventRegistrations stub: nothing to cancel (Epic-02 not installed)', ['profile' => $playerProfileId, 'organization' => $organizationId]);

        return 0;
    }
}
