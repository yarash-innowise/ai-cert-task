<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use Tests\Http\Organization\OrganizationHttpTestCase;

/** Fixtures for the availability tests. The frozen clock is 2030-03-01 10:00 UTC (a Friday). */
abstract class AvailabilityHttpTestCase extends OrganizationHttpTestCase
{
    protected function emailOf(string $userId): string
    {
        return (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $userId]);
    }

    protected function signInAsUser(string $userId, string $password = 'correct horse battery'): void
    {
        $this->cookies = [];
        $this->login($this->emailOf($userId), $password);
    }

    protected function coachIn(string $organizationId, string $email = 'coach@example.com', string $name = 'Casey Coach'): string
    {
        $coach = $this->coachUser($email, $name);
        $this->pdo->prepare('INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)')->execute(['o' => $organizationId, 'c' => $coach]);

        return $coach;
    }

    protected function signInAsCoachUser(string $coachId): void
    {
        $this->cookies = [];
        $this->login($this->emailOf($coachId), self::COACH_PASSWORD);
    }

    protected function slot(string $subjectType, string $subjectId, int $weekday, string $start, string $end): void
    {
        $this->pdo->prepare('INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES (:t, :s, :d, :a, :b)')
            ->execute(['t' => $subjectType, 's' => $subjectId, 'd' => $weekday, 'a' => $start, 'b' => $end]);
    }

    protected function notAvailable(string $subjectType, string $subjectId, int $weekday): void
    {
        $this->pdo->prepare('INSERT INTO availability_unavailable_days (subject_type, subject_id, weekday) VALUES (:t, :s, :d)')
            ->execute(['t' => $subjectType, 's' => $subjectId, 'd' => $weekday]);
    }

    /**
     * Form body for the seven-day form. A day maps to 'none', 'unavailable' or a list of [start, end] pairs.
     *
     * @param array<int, string|list<array{string, string}>> $days
     * @return array<string, mixed>
     */
    protected function week(array $days): array
    {
        $body = [];
        foreach ($days as $day => $value) {
            if (is_string($value)) {
                $body[$day] = ['status' => $value];
                continue;
            }
            $slots = [];
            foreach ($value as [$start, $end]) {
                $slots[] = ['start' => $start, 'end' => $end];
            }
            $body[$day] = ['status' => 'available', 'slots' => $slots];
        }

        return ['days' => $body];
    }

    protected function slotCount(string $subjectType, string $subjectId): int
    {
        return (int) $this->scalar('SELECT count(*) FROM availability_slots WHERE subject_type = :t AND subject_id = :s', ['t' => $subjectType, 's' => $subjectId]);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }
}
