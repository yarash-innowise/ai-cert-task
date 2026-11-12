<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\Weekday;
use App\Availability\Domain\WeeklyAvailability;

/**
 * The "Best Times" summary of a player card (AC-63): the three longest available windows of the week
 * (touching ranges joined), longest first; ties go to the earlier day and time. Pure; advisory only.
 */
final class BestTimes
{
    public const LIMIT = 3;

    /** @return list<BestTime> */
    public function of(WeeklyAvailability $week): array
    {
        $windows = [];
        foreach (Weekday::cases() as $day) {
            foreach ($week->merged($day) as $range) {
                $windows[] = new BestTime($day, $range);
            }
        }
        usort($windows, static fn(BestTime $a, BestTime $b): int => [$b->range->duration(), $a->weekday->value, $a->range->start] <=> [$a->range->duration(), $b->weekday->value, $b->range->start]);

        return array_slice($windows, 0, self::LIMIT);
    }
}
