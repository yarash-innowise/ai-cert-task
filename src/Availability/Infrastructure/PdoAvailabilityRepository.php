<?php

declare(strict_types=1);

namespace App\Availability\Infrastructure;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\InvalidAvailability;
use App\Availability\Domain\TimeRange;
use App\Availability\Domain\Weekday;
use App\Availability\Domain\WeeklyAvailability;
use App\Shared\Domain\Uuid;
use PDO;
use PDOException;

final class PdoAvailabilityRepository implements AvailabilityRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function week(AvailabilitySubject $subject, string $subjectId): WeeklyAvailability
    {
        return $this->weeks($subject, [$subjectId])[$subjectId] ?? WeeklyAvailability::empty();
    }

    public function weeks(AvailabilitySubject $subject, array $subjectIds): array
    {
        $ids = array_values(array_filter($subjectIds, static fn(string $id): bool => Uuid::tryFromString($id) !== null));
        if ($ids === []) {
            return [];
        }
        $literal = '{' . implode(',', $ids) . '}';

        $slots = $this->pdo->prepare(
            'SELECT subject_id, weekday, starts_at, ends_at FROM availability_slots
             WHERE subject_type = :type AND subject_id = ANY (:ids::uuid[]) ORDER BY subject_id, weekday, starts_at',
        );
        $slots->execute(['type' => $subject->value, 'ids' => $literal]);
        $days = $this->pdo->prepare(
            'SELECT subject_id, weekday FROM availability_unavailable_days
             WHERE subject_type = :type AND subject_id = ANY (:ids::uuid[]) ORDER BY subject_id, weekday',
        );
        $days->execute(['type' => $subject->value, 'ids' => $literal]);

        /** @var array<string, array<int, list<TimeRange>>> $slotMap */
        $slotMap = [];
        foreach ($slots->fetchAll() as $row) {
            $slotMap[(string) $row['subject_id']][(int) $row['weekday']][] = TimeRange::fromStrings((string) $row['starts_at'], (string) $row['ends_at']);
        }
        /** @var array<string, list<int>> $dayMap */
        $dayMap = [];
        foreach ($days->fetchAll() as $row) {
            $dayMap[(string) $row['subject_id']][] = (int) $row['weekday'];
        }

        $weeks = [];
        foreach ($ids as $id) {
            $weeks[$id] = WeeklyAvailability::create($slotMap[$id] ?? [], $dayMap[$id] ?? []);
        }

        return $weeks;
    }

    public function replace(AvailabilitySubject $subject, string $subjectId, WeeklyAvailability $week): void
    {
        $key = ['type' => $subject->value, 'id' => $subjectId];
        $this->pdo->prepare('DELETE FROM availability_slots WHERE subject_type = :type AND subject_id = :id')->execute($key);
        $this->pdo->prepare('DELETE FROM availability_unavailable_days WHERE subject_type = :type AND subject_id = :id')->execute($key);

        $insertSlot = $this->pdo->prepare(
            'INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES (:type, :id, :day, :starts, :ends)',
        );
        $insertDay = $this->pdo->prepare(
            'INSERT INTO availability_unavailable_days (subject_type, subject_id, weekday) VALUES (:type, :id, :day)',
        );
        try {
            foreach (Weekday::cases() as $day) {
                foreach ($week->slotsOn($day) as $range) {
                    $insertSlot->execute([...$key, 'day' => $day->value, 'starts' => $range->startLabel() . ':00', 'ends' => $range->endLabel() . ':00']);
                }
                if ($week->isUnavailableOn($day)) {
                    $insertDay->execute([...$key, 'day' => $day->value]);
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23P01' || $e->getCode() === '23514') {
                throw new InvalidAvailability(['week' => 'Some times overlap or end before they start. Check each day and try again.']);
            }

            throw $e;
        }
    }
}
