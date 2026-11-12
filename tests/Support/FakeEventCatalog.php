<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integration\Application\EventCatalog;
use App\Integration\Application\EventSummary;
use App\Shared\Domain\OrganizationId;

/** Test double for the events capability (Epic-02): events are registered per organization with the title and price the catalog owns. */
final class FakeEventCatalog implements EventCatalog
{
    /** @var array<string, EventSummary> keyed "organization:event" */
    private array $events = [];

    public function add(string $organizationId, string $eventRef, string $title = 'Spring Camp', ?int $priceMinor = 2500, string $currency = 'USD'): void
    {
        $this->events[$organizationId . ':' . $eventRef] = new EventSummary($eventRef, $title, new \DateTimeImmutable('2030-04-01 10:00:00+00'), $priceMinor, $currency);
    }

    public function eligibleEvents(?OrganizationId $organization, ?string $playerProfileId = null): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(string $key): bool => $organization === null || str_starts_with($key, $organization->value . ':'),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function exists(string $eventRef): bool
    {
        foreach ($this->events as $event) {
            if ($event->id === $eventRef) {
                return true;
            }
        }

        return false;
    }

    public function find(OrganizationId $organization, string $eventRef): ?EventSummary
    {
        return $this->events[$organization->value . ':' . $eventRef] ?? null;
    }
}
