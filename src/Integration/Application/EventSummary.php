<?php

declare(strict_types=1);

namespace App\Integration\Application;

/**
 * An event as the catalog (Epic-02) describes it to this epic: enough to list it and to request it. The title and the
 * price are the catalog's, never the child's form (F-11). `priceMinor` is the USD price in cents; null means the event
 * has no price this epic can request a purchase for.
 */
final readonly class EventSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public ?int $priceMinor = null,
        public string $currency = 'USD',
    ) {}
}
