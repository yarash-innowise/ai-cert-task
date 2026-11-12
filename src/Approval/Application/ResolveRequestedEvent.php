<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Integration\Application\EventCatalog;
use App\Integration\Application\EventSummary;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\ValidationFailed;

/**
 * Looks the event a child asks for up in the events catalog, scoped to the active organization. The title and the price
 * of a request come from here and never from the child's form (F-11, AC-38, AC-41); an event the catalog does not know
 * in that organization (unknown, or another Trainer's) refuses the request with one clear message (422).
 */
final class ResolveRequestedEvent
{
    public function __construct(private readonly EventCatalog $events) {}

    /** @throws ValidationFailed the event is unknown in this organization */
    public function execute(OrganizationId $organization, string $eventRef): EventSummary
    {
        return $this->events->find($organization, $eventRef)
            ?? throw ValidationFailed::forField('event_ref', 'This event is not available. Choose an event from your Trainer\'s list.');
    }

    /** @throws ValidationFailed the event has no purchasable price */
    public function priceOf(EventSummary $event): int
    {
        if ($event->priceMinor === null || $event->priceMinor <= 0 || $event->currency !== 'USD') {
            throw ValidationFailed::forField('event_ref', 'This event cannot be bought online.');
        }

        return $event->priceMinor;
    }
}
