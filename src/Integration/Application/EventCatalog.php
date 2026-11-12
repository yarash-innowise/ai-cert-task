<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Shared\Domain\OrganizationId;

/**
 * Port to the events capability (Epic-02): browsing events a player may see. View only; this
 * epic never creates or changes events. Epic-01 binding: EmptyEventCatalog.
 */
interface EventCatalog
{
    /**
     * @param OrganizationId|null $organization the active organization, or null for "all of my Trainers"
     * @param string|null $playerProfileId the acting player profile, when there is one
     * @return list<EventSummary> events eligible to browse (empty when the capability is not available)
     */
    public function eligibleEvents(?OrganizationId $organization, ?string $playerProfileId = null): array;

    public function exists(string $eventRef): bool;

    /**
     * The event as the active organization offers it, or null when it is unknown or belongs to another organization.
     * The approval workflow takes the event title and, for a purchase, the price and currency ONLY from here, never from
     * the child's form (F-04/F-11, AC-38, AC-41): an unknown event refuses the request. Epic-02/Epic-05 MUST supply
     * `EventSummary::$priceMinor` for every purchasable event; until then no purchase can be requested.
     */
    public function find(OrganizationId $organization, string $eventRef): ?EventSummary;
}
