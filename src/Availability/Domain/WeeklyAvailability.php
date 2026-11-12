<?php

declare(strict_types=1);

namespace App\Availability\Domain;

/**
 * One subject's week: available time ranges per day, or an explicit "Not Available" day. A day that is
 * neither is "no preference". A week with no entry at all means no preference was ever given, which is
 * never read as "unavailable" (advisory only).
 */
final readonly class WeeklyAvailability
{
    /**
     * @param array<int, list<TimeRange>> $slots weekday => ranges, ordered by start, never overlapping
     * @param list<int> $unavailable weekdays marked "Not Available"
     */
    private function __construct(private array $slots, private array $unavailable) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param array<int, list<TimeRange>> $slots weekday => ranges
     * @param list<int> $unavailable
     * @throws InvalidAvailability overlapping ranges, or a day that is both available and "Not Available"
     */
    public static function create(array $slots, array $unavailable): self
    {
        $errors = [];
        $ordered = [];
        foreach ($slots as $day => $ranges) {
            $weekday = Weekday::tryFrom((int) $day) ?? throw new \InvalidArgumentException('Unknown weekday ' . $day);
            if ($ranges === []) {
                continue;
            }
            usort($ranges, static fn(TimeRange $a, TimeRange $b): int => $a->start <=> $b->start);
            foreach ($ranges as $i => $range) {
                $previous = $ranges[$i - 1] ?? null;
                if ($previous !== null && $previous->overlaps($range)) {
                    $errors['day-' . $day] = sprintf('%s: %s overlaps %s. Change one of them so they do not overlap.', $weekday->label(), $previous, $range);
                    break;
                }
            }
            $ordered[$weekday->value] = $ranges;
        }
        $unavailable = array_values(array_unique($unavailable));
        foreach ($unavailable as $day) {
            if (isset($ordered[$day])) {
                $errors['day-' . $day] ??= sprintf('%s: choose either times or "Not Available", not both.', Weekday::from($day)->label());
            }
        }
        if ($errors !== []) {
            throw new InvalidAvailability($errors);
        }
        ksort($ordered);
        sort($unavailable);

        return new self($ordered, $unavailable);
    }

    /** @return list<TimeRange> */
    public function slotsOn(Weekday $day): array
    {
        return $this->slots[$day->value] ?? [];
    }

    public function isUnavailableOn(Weekday $day): bool
    {
        return in_array($day->value, $this->unavailable, true);
    }

    /** True when the subject gave at least one preference (a range or "Not Available"). */
    public function hasEntries(): bool
    {
        return $this->slots !== [] || $this->unavailable !== [];
    }

    /** @return list<int> */
    public function unavailableDays(): array
    {
        return $this->unavailable;
    }

    /** Whether the whole window lies inside available time of that day (touching ranges count as one). */
    public function covers(Weekday $day, TimeRange $window): bool
    {
        foreach ($this->merged($day) as $range) {
            if ($range->contains($window)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ranges of a day with touching ranges joined (09:00-10:00 and 10:00-11:00 become 09:00-11:00).
     *
     * @return list<TimeRange>
     */
    public function merged(Weekday $day): array
    {
        $merged = [];
        foreach ($this->slotsOn($day) as $range) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];
            if ($last !== null && $last->end >= $range->start) {
                $merged[array_key_last($merged)] = TimeRange::fromMinutes($last->start, max($last->end, $range->end));
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }
}
