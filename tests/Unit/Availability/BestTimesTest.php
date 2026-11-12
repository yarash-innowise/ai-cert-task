<?php

declare(strict_types=1);

namespace Tests\Unit\Availability;

use App\Availability\Application\BestTimes;
use App\Availability\Domain\TimeRange;
use App\Availability\Domain\WeeklyAvailability;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-63')]
final class BestTimesTest extends TestCase
{
    private static function r(string $a, string $b): TimeRange
    {
        return TimeRange::fromStrings($a, $b);
    }

    /**
     * @param list<\App\Availability\Application\BestTime> $times
     * @return list<string>
     */
    private static function labels(array $times): array
    {
        return array_map(static fn($t): string => $t->label(), $times);
    }

    public function testNoEntriesGiveNoBestTimes(): void
    {
        self::assertSame([], (new BestTimes())->of(WeeklyAvailability::empty()));
        self::assertSame([], (new BestTimes())->of(WeeklyAvailability::create([], [2, 3])));
    }

    public function testTheThreeLongestWindowsComeFirstLongestFirst(): void
    {
        $week = WeeklyAvailability::create([
            0 => [self::r('17:00', '18:00')],
            1 => [self::r('09:00', '12:00')],
            2 => [self::r('16:00', '18:00')],
            5 => [self::r('10:00', '14:00'), self::r('15:00', '15:30')],
        ], []);

        self::assertSame(['Sat 10:00-14:00', 'Tue 09:00-12:00', 'Wed 16:00-18:00'], self::labels((new BestTimes())->of($week)));
    }

    public function testTouchingRangesAreJoinedBeforeRanking(): void
    {
        $week = WeeklyAvailability::create([
            0 => [self::r('09:00', '10:00'), self::r('10:00', '11:00'), self::r('11:00', '12:00')],
            1 => [self::r('09:00', '11:30')],
        ], []);

        self::assertSame(['Mon 09:00-12:00', 'Tue 09:00-11:30'], self::labels((new BestTimes())->of($week)));
    }

    public function testTiesGoToTheEarlierDayThenTheEarlierTime(): void
    {
        $week = WeeklyAvailability::create([
            3 => [self::r('08:00', '09:00')],
            1 => [self::r('18:00', '19:00'), self::r('07:00', '08:00')],
            0 => [self::r('12:00', '13:00')],
        ], []);

        self::assertSame(['Mon 12:00-13:00', 'Tue 07:00-08:00', 'Tue 18:00-19:00'], self::labels((new BestTimes())->of($week)));
    }
}
