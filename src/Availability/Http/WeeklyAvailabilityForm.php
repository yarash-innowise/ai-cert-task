<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Domain\InvalidAvailability;
use App\Availability\Domain\TimeRange;
use App\Availability\Domain\Weekday;
use App\Availability\Domain\WeeklyAvailability;
use App\Shared\Http\Exception\ValidationFailed;

/**
 * The seven-day availability form shared by players, parents and Coaches. Each day is one of
 * "none" (no preference), "unavailable" ("Not Available") or "available" with up to {@see self::ROWS} time rows.
 * Field names: days[N][status], days[N][slots][I][start|end]. Errors are keyed "day-N" and name the day.
 */
final class WeeklyAvailabilityForm
{
    public const ROWS = 5;
    public const NONE = 'none';
    public const UNAVAILABLE = 'unavailable';
    public const AVAILABLE = 'available';

    /**
     * @param array<array-key, mixed> $body the parsed form body
     * @throws ValidationFailed a problem naming the day
     */
    public static function parse(array $body): WeeklyAvailability
    {
        $days = is_array($body['days'] ?? null) ? $body['days'] : [];
        $errors = [];
        $slots = [];
        $unavailable = [];

        foreach (Weekday::cases() as $weekday) {
            $day = is_array($days[$weekday->value] ?? null) ? $days[$weekday->value] : [];
            $status = is_string($day['status'] ?? null) ? $day['status'] : self::NONE;
            if ($status === self::UNAVAILABLE) {
                $unavailable[] = $weekday->value;
                continue;
            }
            if ($status !== self::AVAILABLE) {
                continue;
            }
            $ranges = [];
            $rows = is_array($day['slots'] ?? null) ? array_slice($day['slots'], 0, self::ROWS * 2) : [];
            foreach ($rows as $row) {
                $start = is_array($row) && is_string($row['start'] ?? null) ? trim($row['start']) : '';
                $end = is_array($row) && is_string($row['end'] ?? null) ? trim($row['end']) : '';
                if ($start === '' && $end === '') {
                    continue;
                }
                if ($start === '' || $end === '') {
                    $errors['day-' . $weekday->value] = $weekday->label() . ': fill in both the start and the end time of each row.';
                    continue 2;
                }
                try {
                    $ranges[] = TimeRange::fromStrings($start, $end);
                } catch (\InvalidArgumentException $e) {
                    $errors['day-' . $weekday->value] = self::isOrder($start, $end)
                        ? sprintf('%s: %s to %s - the end time must be after the start time.', $weekday->label(), $start, $end)
                        : $weekday->label() . ': ' . $e->getMessage();
                    continue 2;
                }
            }
            if ($ranges === []) {
                $errors['day-' . $weekday->value] = $weekday->label() . ': add a time range, or choose "No preference" or "Not Available".';
                continue;
            }
            $slots[$weekday->value] = $ranges;
        }

        if ($errors === []) {
            try {
                return WeeklyAvailability::create($slots, $unavailable);
            } catch (InvalidAvailability $e) {
                $errors = $e->errors;
            }
        }

        throw new ValidationFailed($errors, null, ['days' => self::oldRows($body)]);
    }

    /**
     * Rows for the template from a saved week.
     *
     * @return list<array{day: Weekday, status: string, slots: list<array{start: string, end: string}>}>
     */
    public static function rows(WeeklyAvailability $week): array
    {
        $rows = [];
        foreach (Weekday::cases() as $day) {
            $slots = array_map(static fn(TimeRange $r): array => ['start' => $r->startLabel(), 'end' => $r->endLabel()], $week->slotsOn($day));
            $status = $week->isUnavailableOn($day) ? self::UNAVAILABLE : ($slots === [] ? self::NONE : self::AVAILABLE);
            $rows[] = ['day' => $day, 'status' => $status, 'slots' => self::pad($slots)];
        }

        return $rows;
    }

    /**
     * The submitted rows again, so a refused form keeps what the person typed.
     *
     * @param array<array-key, mixed> $body
     * @return list<array{day: Weekday, status: string, slots: list<array{start: string, end: string}>}>
     */
    public static function oldRows(array $body): array
    {
        $days = is_array($body['days'] ?? null) ? $body['days'] : [];
        $rows = [];
        foreach (Weekday::cases() as $day) {
            $posted = is_array($days[$day->value] ?? null) ? $days[$day->value] : [];
            $status = is_string($posted['status'] ?? null) && in_array($posted['status'], [self::NONE, self::UNAVAILABLE, self::AVAILABLE], true) ? $posted['status'] : self::NONE;
            $slots = [];
            foreach (is_array($posted['slots'] ?? null) ? array_slice($posted['slots'], 0, self::ROWS) : [] as $row) {
                $slots[] = [
                    'start' => is_array($row) && is_string($row['start'] ?? null) ? $row['start'] : '',
                    'end' => is_array($row) && is_string($row['end'] ?? null) ? $row['end'] : '',
                ];
            }
            $rows[] = ['day' => $day, 'status' => $status, 'slots' => self::pad($slots)];
        }

        return $rows;
    }

    /**
     * @param list<array{start: string, end: string}> $slots
     * @return list<array{start: string, end: string}>
     */
    private static function pad(array $slots): array
    {
        $slots = array_slice($slots, 0, self::ROWS);
        while (count($slots) < self::ROWS) {
            $slots[] = ['start' => '', 'end' => ''];
        }

        return $slots;
    }

    private static function isOrder(string $start, string $end): bool
    {
        try {
            return TimeRange::minuteOf($end) <= TimeRange::minuteOf($start);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
