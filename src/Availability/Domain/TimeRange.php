<?php

declare(strict_types=1);

namespace App\Availability\Domain;

use InvalidArgumentException;

/** A wall-clock time range within one day, kept as minutes since midnight; the end is after the start. */
final readonly class TimeRange implements \Stringable
{
    private function __construct(public int $start, public int $end) {}

    /** @throws InvalidArgumentException the end is not after the start, or a value is outside the day */
    public static function fromMinutes(int $start, int $end): self
    {
        if ($start < 0 || $end > 1439 || $end <= $start) {
            throw new InvalidArgumentException('The end time must be after the start time.');
        }

        return new self($start, $end);
    }

    /** @throws InvalidArgumentException not "HH:MM" (or "HH:MM:SS"), or the end is not after the start */
    public static function fromStrings(string $start, string $end): self
    {
        return self::fromMinutes(self::minuteOf($start), self::minuteOf($end));
    }

    /** @throws InvalidArgumentException not a time of day */
    public static function minuteOf(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($time), $m) !== 1) {
            throw new InvalidArgumentException('Use a time such as 17:30.');
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    public static function format(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    public function includes(int $minute): bool
    {
        return $this->start <= $minute && $minute < $this->end;
    }

    public function duration(): int
    {
        return $this->end - $this->start;
    }

    public function startLabel(): string
    {
        return self::format($this->start);
    }

    public function endLabel(): string
    {
        return self::format($this->end);
    }

    public function __toString(): string
    {
        return $this->startLabel() . '-' . $this->endLabel();
    }
}
