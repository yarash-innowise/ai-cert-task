<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** The assigned Coach, overridden or not, sees the assignment and accepts it or asks for a change (AC-67). */
final class CoachAcceptTest extends AssignmentHttpTestCase
{
    private string $assignment;
    private string $assignmentInside;

    protected function setUp(): void
    {
        parent::setUp();
        // one assignment inside the schedule (Monday), one outside it, overridden (Tuesday)
        $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['event_ref' => 'EVT-IN']));
        $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['event_ref' => 'EVT-OUT', 'continue_anyway' => '1', 'reason' => 'Short of coaches']));
        $this->assignmentInside = (string) $this->scalar("SELECT id FROM coach_assignments WHERE event_ref = 'EVT-IN'");
        $this->assignment = (string) $this->scalar("SELECT id FROM coach_assignments WHERE event_ref = 'EVT-OUT'");
        $this->signInAsCoachUser($this->coach);
    }

    #[Group('AC-67')]
    public function testTheOverriddenCoachStillSeesTheAssignmentOnTheirDashboard(): void
    {
        $body = $this->body($this->get('/coach'));

        self::assertStringContainsString('EVT-OUT', $body);
        self::assertStringContainsString('EVT-IN', $body);
        self::assertStringContainsString('Outside your schedule', $body);
        self::assertStringContainsString('Short of coaches', $body);
        self::assertStringContainsString('action="/coach/assignments/' . $this->assignment . '/accept"', $body);
        self::assertStringContainsString('action="/coach/assignments/' . $this->assignment . '/change-request"', $body);
        self::assertStringContainsString('Waiting for the Coach', $body);
    }

    #[Group('AC-67')]
    public function testTheCoachAcceptsAnOverriddenAssignment(): void
    {
        $response = $this->post('/coach/assignments/' . $this->assignment . '/accept');

        self::assertSame('/coach?notice=accepted', $this->location($response));
        $row = $this->row('SELECT status, responded_at FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]);
        self::assertIsArray($row);
        self::assertSame('accepted', $row['status']);
        self::assertNotNull($row['responded_at']);
        $page = $this->body($this->get('/coach?notice=accepted'));
        self::assertStringContainsString('You accepted the assignment.', $page);
        self::assertStringContainsString('Accepted', $page);
        // accepting leaves the override in place
        self::assertNotNull($this->scalar('SELECT override_id FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]));
    }

    #[Group('AC-67')]
    public function testTheCoachAsksForAChangeAndTheTrainerIsTold(): void
    {
        $response = $this->post('/coach/assignments/' . $this->assignment . '/change-request', ['note' => '  I teach another class then  ']);

        self::assertSame('/coach?notice=change', $this->location($response));
        $row = $this->row('SELECT status, change_note FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]);
        self::assertIsArray($row);
        self::assertSame('change_requested', $row['status']);
        self::assertSame('I teach another class then', $row['change_note']);
        $note = $this->row("SELECT * FROM notifications WHERE user_id = :t AND kind = 'coach_change_requested'", ['t' => $this->trainer]);
        self::assertIsArray($note);
        self::assertStringContainsString('EVT-OUT', (string) $note['title']);
        self::assertSame('I teach another class then', $note['body']);
        self::assertStringContainsString('Your change request was sent', $this->body($this->get('/coach?notice=change')));
        // the Trainer sees the state
        $this->signInAsUser($this->trainer);
        self::assertStringContainsString('Change requested', $this->body($this->get('/trainer/assignments')));
    }

    #[Group('AC-67')]
    public function testAChangeRequestNeedsNoNoteButALongNoteIsRefused(): void
    {
        self::assertSame(302, $this->post('/coach/assignments/' . $this->assignment . '/change-request', ['note' => ''])->getStatusCode());
        self::assertNull($this->scalar('SELECT change_note FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]));

        $long = $this->post('/coach/assignments/' . $this->assignmentInside . '/change-request', ['note' => str_repeat('x', 501)]);
        self::assertSame(422, $long->getStatusCode());
        self::assertSame('assigned', $this->scalar('SELECT status FROM coach_assignments WHERE id = :i', ['i' => $this->assignmentInside]));
    }

    #[Group('AC-67')]
    public function testAnotherCoachCannotAnswerSomeoneElsesAssignment(): void
    {
        $other = $this->coachIn($this->org, 'other@example.com', 'Otto Other');
        $this->signInAsCoachUser($other);

        self::assertStringNotContainsString('EVT-OUT', $this->body($this->get('/coach')));
        self::assertSame(404, $this->post('/coach/assignments/' . $this->assignment . '/accept')->getStatusCode());
        self::assertSame(404, $this->post('/coach/assignments/' . $this->assignment . '/change-request', ['note' => 'x'])->getStatusCode());
        self::assertSame('assigned', $this->scalar('SELECT status FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]));
    }

    #[Group('AC-67'), Group('AC-10')]
    public function testACoachOfAnotherOrganizationCannotAnswerEither(): void
    {
        $foreign = $this->coachIn($this->trainerWithOrganization('Beta Padel Club')[1], 'foreign@example.com', 'Fay Foreign');
        $this->signInAsCoachUser($foreign);

        self::assertSame(404, $this->post('/coach/assignments/' . $this->assignment . '/accept')->getStatusCode());
        self::assertSame(404, $this->post('/coach/assignments/00000000-0000-7000-8000-000000000000/accept')->getStatusCode());
        self::assertSame(404, $this->post('/coach/assignments/not-an-id/accept')->getStatusCode());
        self::assertStringNotContainsString('EVT-', $this->body($this->get('/coach')));
    }

    #[Group('AC-67')]
    public function testOnlyCoachesAnswerAssignments(): void
    {
        $this->signInAsUser($this->trainer);
        $asTrainer = $this->post('/coach/assignments/' . $this->assignment . '/accept')->getStatusCode();
        $this->signInAs('player');
        $asPlayer = $this->post('/coach/assignments/' . $this->assignment . '/accept')->getStatusCode();
        self::assertSame([403, 403], [$asTrainer, $asPlayer]);
        self::assertSame('assigned', $this->scalar('SELECT status FROM coach_assignments WHERE id = :i', ['i' => $this->assignment]));
    }

    #[Group('AC-67')]
    public function testACoachWithoutAnAssignmentSeesAnEmptyDashboard(): void
    {
        $lone = $this->coachIn($this->org, 'lone@example.com', 'Lone Coach');
        $this->signInAsCoachUser($lone);

        self::assertStringContainsString('You have no assignments yet.', $this->body($this->get('/coach')));
    }
}
