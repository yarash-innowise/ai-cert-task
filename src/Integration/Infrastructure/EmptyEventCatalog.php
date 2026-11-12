<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\EventCatalog;
use App\Integration\Application\EventSummary;
use App\Shared\Domain\OrganizationId;
use Psr\Log\LoggerInterface;

/** Stand-in until Epic-02 exists: there are no events. */
final class EmptyEventCatalog implements EventCatalog
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function eligibleEvents(?OrganizationId $organization, ?string $playerProfileId = null): array
    {
        $this->logger->info('EventCatalog stub: no events available (Epic-02 not installed)');

        return [];
    }

    public function find(OrganizationId $organization, string $eventRef): ?EventSummary
    {
        $this->logger->info('EventCatalog stub: event not found (Epic-02 not installed)');

        return null;
    }

    public function exists(string $eventRef): bool
    {
        $this->logger->info('EventCatalog stub: no events exist (Epic-02 not installed)');

        return false;
    }
}
