<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\WeeklyAvailability;
use App\Organization\Application\RosterPlayer;

/**
 * A player card on the Trainer's availability page. `match` is only set while a day/time filter is
 * active: "available" (their saved times fit) or "no_preference" (nothing saved that rules them out).
 */
final readonly class PlayerAvailabilityCard
{
    /** @param list<BestTime> $bestTimes */
    public function __construct(
        public RosterPlayer $player,
        public WeeklyAvailability $week,
        public array $bestTimes,
        public ?string $match,
    ) {}

    public function hasPreference(): bool
    {
        return $this->week->hasEntries();
    }
}
