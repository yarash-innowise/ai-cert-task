<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

/**
 * Fixtures for assigning Coaches. Casey Coach works Monday 09:00-12:00 and Wednesday 14:00-18:00. The frozen clock
 * is Friday 2030-03-01, so 2030-03-04 is a Monday and 2030-03-05 a Tuesday.
 */
abstract class AssignmentHttpTestCase extends AvailabilityHttpTestCase
{
    protected string $trainer;
    protected string $org;
    protected string $coach;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainer, $this->org] = $this->trainerSignedIn();
        $this->signOut();
        $this->coach = $this->coachIn($this->org, 'coach@example.com', 'Casey Coach');
        // Casey works Monday 09:00-12:00 and Wednesday 14:00-18:00
        $this->slot('coach', $this->coach, 0, '09:00', '12:00');
        $this->slot('coach', $this->coach, 2, '14:00', '18:00');
        $this->signInAsUser($this->trainer);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    protected function form(string $date = '2030-03-04', string $start = '10:00', string $end = '11:00', array $extra = []): array
    {
        return ['event_ref' => 'EVT-100', 'event_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'coach_user_id' => $this->coach, ...$extra];
    }

    protected function assignments(): int
    {
        return (int) $this->scalar('SELECT count(*) FROM coach_assignments WHERE organization_id = :o', ['o' => $this->org]);
    }
}
