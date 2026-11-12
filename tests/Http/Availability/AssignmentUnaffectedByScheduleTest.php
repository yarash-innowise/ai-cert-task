<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** Changing a schedule after an assignment never touches it, and nobody is warned retroactively (edge case, Q-01.06). */
final class AssignmentUnaffectedByScheduleTest extends AssignmentHttpTestCase
{
    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'assignments' => $this->rows('SELECT id, status, override_id, event_ref, event_date, starts_at, ends_at, change_note FROM coach_assignments ORDER BY id'),
            'overrides' => $this->rows('SELECT * FROM coach_availability_overrides ORDER BY id'),
            'audit' => (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'coach.availability_override'"),
            'notifications' => (int) $this->scalar('SELECT count(*) FROM notifications'),
            'outbox' => (int) $this->scalar('SELECT count(*) FROM outbox'),
        ];
    }

    #[Group('AC-65'), Group('AC-67')]
    public function testEditingTheScheduleAfterAssignmentsLeavesThemUntouched(): void
    {
        $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['event_ref' => 'EVT-IN']));
        $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['event_ref' => 'EVT-OUT', 'continue_anyway' => '1', 'reason' => 'Short of coaches']));
        $before = $this->snapshot();
        self::assertCount(2, $before['assignments']);

        $this->signInAsCoachUser($this->coach);
        // the Coach drops Monday altogether and adds Tuesday
        $response = $this->post('/coach/schedule', $this->week([1 => [['08:00', '12:00']], 0 => 'unavailable']));
        self::assertSame(302, $response->getStatusCode());
        // and later clears the schedule completely
        $this->post('/coach/schedule', $this->week([]));

        self::assertSame($before, $this->snapshot());
        $page = $this->body($this->get('/coach'));
        self::assertStringContainsString('EVT-IN', $page);
        self::assertStringContainsString('EVT-OUT', $page);
        self::assertStringNotContainsString('not available', strtolower(strip_tags(str_replace('Outside your schedule', '', $page))), 'no retroactive warning');
    }

    #[Group('AC-65')]
    public function testAnAcceptedAssignmentStaysAcceptedWhenTheScheduleChanges(): void
    {
        $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00'));
        $id = (string) $this->scalar('SELECT id FROM coach_assignments');
        $this->signInAsCoachUser($this->coach);
        $this->post('/coach/assignments/' . $id . '/accept');

        $this->post('/coach/schedule', $this->week([3 => [['10:00', '11:00']]]));

        self::assertSame('accepted', $this->scalar('SELECT status FROM coach_assignments WHERE id = :i', ['i' => $id]));
    }

    #[Group('AC-65')]
    public function testTheScheduleAtAssignmentTimeDecidesTheWarningNotALaterOne(): void
    {
        $this->signInAsCoachUser($this->coach);
        $this->post('/coach/schedule', $this->week([1 => [['08:00', '12:00']]]));
        $this->signInAsUser($this->trainer);

        // Tuesday is now inside the schedule: no warning; Monday (formerly fine) now needs one
        self::assertSame(302, $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00'))->getStatusCode());
        $monday = $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['event_ref' => 'EVT-200']));
        self::assertStringContainsString('is not available at this time', html_entity_decode($this->body($monday)));
    }
}
