<?php

declare(strict_types=1);

namespace App\Approval\Application;

/**
 * What the child's screen (Epic-02's event page) sends: the event reference and a note (and, for a token spend, the number of
 * tokens in `amountMinor`). The event title and a purchase's price are NOT taken from the child: the approval workflow looks them
 * up through the EventCatalog (F-11); `eventTitle`, `amountMinor` (outside token spends) and `currency` are ignored on input.
 */
final readonly class NewRequestInput
{
    public function __construct(
        public string $eventRef,
        public ?string $eventTitle = null,
        public int $amountMinor = 0,
        public string $currency = 'USD',
        public ?string $childNote = null,
    ) {}
}
