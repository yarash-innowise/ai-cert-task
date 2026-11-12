<?php

declare(strict_types=1);

namespace App\Availability\Domain;

interface AvailabilityRepository
{
    public function week(AvailabilitySubject $subject, string $subjectId): WeeklyAvailability;

    /**
     * Several subjects in two statements, however many there are (no N+1).
     *
     * @param list<string> $subjectIds
     * @return array<string, WeeklyAvailability> subject id => week (a subject without entries maps to an empty week)
     */
    public function weeks(AvailabilitySubject $subject, array $subjectIds): array;

    /**
     * Replaces the subject's whole week. Call inside a transaction.
     *
     * @throws InvalidAvailability the database refused overlapping ranges
     */
    public function replace(AvailabilitySubject $subject, string $subjectId, WeeklyAvailability $week): void;
}
