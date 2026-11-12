<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\TimeRange;
use App\Availability\Domain\Weekday;

/** One "Best Times" window of a player: a weekday and a time range, e.g. "Mon 17:00-19:00". */
final readonly class BestTime
{
    public function __construct(public Weekday $weekday, public TimeRange $range) {}

    public function label(): string
    {
        return $this->weekday->short() . ' ' . $this->range;
    }
}
