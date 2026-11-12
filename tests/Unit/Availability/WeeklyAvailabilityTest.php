<?php

declare(strict_types=1);

namespace Tests\Unit\Availability;

use App\Availability\Domain\InvalidAvailability;
use App\Availability\Domain\TimeRange;
use App\Availability\Domain\Weekday;
use App\Availability\Domain\WeeklyAvailability;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-61'), Group('AC-64')]
final class WeeklyAvailabilityTest extends TestCase
{
    public function testATimeRangeNeedsAnEndAfterTheStart(): void
    {
        self::assertSame('09:00-10:30', (string) TimeRange::fromStrings('09:00', '10:30'));
        self::assertSame('09:00-10:30', (string) TimeRange::fromStrings('09:00:00', '10:30:00'));
        foreach ([['10:00', '09:00'], ['10:00', '10:00'], ['25:00', '26:00'], ['9am', '10:00'], ['', '10:00']] as [$a, $b]) {
            try {
                TimeRange::fromStrings($a, $b);
                self::fail("$a-$b must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRangesOverlapOnlyWhenTheyShareTime(): void
    {
        $a = TimeRange::fromStrings('09:00', '10:00');
        self::assertFalse($a->overlaps(TimeRange::fromStrings('10:00', '11:00')));
        self::assertTrue($a->overlaps(TimeRange::fromStrings('09:59', '11:00')));
        self::assertTrue($a->contains(TimeRange::fromStrings('09:15', '09:45')));
        self::assertFalse($a->contains(TimeRange::fromStrings('09:15', '10:15')));
    }

    public function testOverlappingRangesOfADayAreNamed(): void
    {
        try {
            WeeklyAvailability::create([2 => [TimeRange::fromStrings('10:00', '12:00'), TimeRange::fromStrings('09:00', '10:30')]], []);
            self::fail('overlap must be refused');
        } catch (InvalidAvailability $e) {
            self::assertSame(['day-2' => 'Wednesday: 09:00-10:30 overlaps 10:00-12:00. Change one of them so they do not overlap.'], $e->errors);
        }
    }

    public function testADayCannotBeBothAvailableAndNotAvailable(): void
    {
        $this->expectException(InvalidAvailability::class);
        WeeklyAvailability::create([0 => [TimeRange::fromStrings('09:00', '10:00')]], [0]);
    }

    public function testAnEmptyWeekHasNoEntries(): void
    {
        $week = WeeklyAvailability::empty();
        self::assertFalse($week->hasEntries());
        self::assertTrue(WeeklyAvailability::create([], [3])->hasEntries());
        self::assertTrue(WeeklyAvailability::create([1 => [TimeRange::fromStrings('09:00', '10:00')]], [])->hasEntries());
    }

    public function testACoveredWindowMustFitInsideAvailableTimeOfThatDay(): void
    {
        $week = WeeklyAvailability::create([
            0 => [TimeRange::fromStrings('09:00', '12:00'), TimeRange::fromStrings('12:00', '13:00'), TimeRange::fromStrings('16:00', '18:00')],
        ], [1]);

        self::assertTrue($week->covers(Weekday::Monday, TimeRange::fromStrings('10:00', '12:30')), 'touching ranges are one');
        self::assertTrue($week->covers(Weekday::Monday, TimeRange::fromStrings('16:00', '18:00')));
        self::assertFalse($week->covers(Weekday::Monday, TimeRange::fromStrings('12:30', '16:30')));
        self::assertFalse($week->covers(Weekday::Monday, TimeRange::fromStrings('17:00', '18:30')));
        self::assertFalse($week->covers(Weekday::Tuesday, TimeRange::fromStrings('10:00', '11:00')));
        self::assertFalse($week->covers(Weekday::Wednesday, TimeRange::fromStrings('10:00', '11:00')));
    }

    public function testWeekdayOfADateIsMondayBased(): void
    {
        self::assertSame(Weekday::Friday, Weekday::ofDate(new \DateTimeImmutable('2030-03-01')));
        self::assertSame(Weekday::Sunday, Weekday::ofDate(new \DateTimeImmutable('2030-03-03')));
        self::assertSame(Weekday::Monday, Weekday::ofDate(new \DateTimeImmutable('2030-03-04')));
    }
}
