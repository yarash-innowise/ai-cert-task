<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** Assigning a Coach to an event reference, the schedule warning and the override (AC-65, AC-66, AC-87). */
final class AssignCoachTest extends AssignmentHttpTestCase
{
    #[Group('AC-65')]
    public function testAssigningInsideTheScheduleNeedsNoWarning(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:30'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->assignments());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_availability_overrides'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'coach.availability_override'"));
        self::assertStringContainsString('The Coach was assigned', $this->body($this->get($this->location($response))));
    }

    #[Group('AC-65')]
    public function testOutsideTheScheduleShowsTheExactWarningAndWritesNothing(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00')); // a Tuesday

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Coach Casey Coach is not available at this time per their schedule. Continue anyway?', html_entity_decode($this->body($response)));
        self::assertStringContainsString('name="continue_anyway"', $this->body($response));
        self::assertStringContainsString('name="reason"', $this->body($response));
        self::assertSame(0, $this->assignments());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_availability_overrides'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM notifications WHERE user_id = :c", ['c' => $this->coach]));
    }

    #[Group('AC-65')]
    public function testPartiallyOutsideTheScheduleAlsoWarns(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-04', '11:00', '13:00')); // Monday 11-13, slot ends 12:00

        self::assertStringContainsString('is not available at this time per their schedule', html_entity_decode($this->body($response)));
        self::assertSame(0, $this->assignments());
    }

    #[Group('AC-65')]
    public function testANotAvailableDayWarnsAndACoachWithoutAScheduleNeverDoes(): void
    {
        $this->notAvailable('coach', $this->coach, 4); // Friday
        $friday = $this->post('/trainer/assignments', $this->form('2030-03-08', '10:00', '11:00'));
        self::assertStringContainsString('is not available at this time', html_entity_decode($this->body($friday)));

        $free = $this->coachIn($this->org, 'free@example.com', 'Free Coach');
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '03:00', '04:00', ['coach_user_id' => $free]));
        self::assertSame(302, $response->getStatusCode(), 'no schedule means no declared availability, so nothing conflicts');
    }

    #[Group('AC-65'), Group('AC-66'), Group('AC-87')]
    public function testContinuingWithAReasonAssignsAndLogsTheOverride(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['continue_anyway' => '1', 'reason' => '  Regular coach is ill  ']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->assignments());
        $override = $this->row('SELECT * FROM coach_availability_overrides');
        self::assertIsArray($override);
        self::assertSame('EVT-100', $override['event_ref']);
        self::assertSame($this->coach, $override['coach_user_id']);
        self::assertSame('Regular coach is ill', $override['reason']);
        self::assertSame($this->trainer, $override['overridden_by']);
        self::assertSame($this->org, $override['organization_id']);
        $assignment = $this->row('SELECT * FROM coach_assignments');
        self::assertIsArray($assignment);
        self::assertSame($override['id'], $assignment['override_id']);
        self::assertSame('assigned', $assignment['status']);

        $audit = $this->row("SELECT * FROM audit_log WHERE action = 'coach.availability_override'");
        self::assertIsArray($audit);
        self::assertSame($this->trainer, $audit['actor_user_id']);
        self::assertSame($this->org, $audit['organization_id']);
        self::assertSame($assignment['id'], $audit['subject_id']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $audit['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EVT-100', $payload['event_ref']);
        self::assertSame($this->coach, $payload['coach_user_id']);
        self::assertSame($this->trainer, $payload['overridden_by']);
        self::assertSame($override['id'], $payload['override_id']);
        self::assertSame('Ace Tennis Academy', $payload['business_name']);
    }

    #[Group('AC-87')]
    public function testTheOverrideAuditRowCannotBeEditedOrDeleted(): void
    {
        $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['continue_anyway' => '1', 'reason' => 'Needed']));
        $id = $this->scalar("SELECT id FROM audit_log WHERE action = 'coach.availability_override'");

        foreach (["UPDATE audit_log SET action = 'x' WHERE id = :i", 'DELETE FROM audit_log WHERE id = :i'] as $sql) {
            $this->pdo->exec('SAVEPOINT a1');
            try {
                $this->pdo->prepare($sql)->execute(['i' => $id]);
                self::fail('the audit log must be append-only: ' . $sql);
            } catch (\PDOException $e) {
                // The application role has no UPDATE/DELETE on audit_log (privilege check); the trigger backs that up for the owner.
                self::assertMatchesRegularExpression('/append-only|permission denied/', $e->getMessage());
            }
            $this->pdo->exec('ROLLBACK TO SAVEPOINT a1');
        }
        // the override record itself is insert-only for the application role
        foreach (['UPDATE', 'DELETE'] as $privilege) {
            self::assertFalse((bool) $this->scalar("SELECT has_table_privilege('app_rw', 'coach_availability_overrides', '" . $privilege . "')"));
        }
        self::assertTrue((bool) $this->scalar("SELECT has_table_privilege('app_rw', 'coach_availability_overrides', 'INSERT')"));
    }

    #[Group('AC-65')]
    public function testTheCoachIsNotifiedByAppAndEmail(): void
    {
        $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00'));

        $note = $this->row("SELECT * FROM notifications WHERE user_id = :c AND kind = 'coach_assignment'", ['c' => $this->coach]);
        self::assertIsArray($note);
        self::assertStringContainsString('EVT-100', (string) $note['title']);
        $email = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'coach-assignment'");
        self::assertIsArray($email);
        self::assertStringContainsString('coach@example.com', (string) $email['payload']);
    }

    #[Group('AC-65')]
    public function testInvalidInputIsRefusedAndAnotherOrganizationsCoachIsNotFound(): void
    {
        self::assertSame(422, $this->post('/trainer/assignments', $this->form('2030-02-30'))->getStatusCode());
        self::assertSame(422, $this->post('/trainer/assignments', $this->form('2030-03-04', '12:00', '11:00'))->getStatusCode());
        self::assertSame(422, $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['event_ref' => '  ']))->getStatusCode());
        self::assertSame(0, $this->assignments());

        $foreign = $this->coachIn($this->trainerWithOrganization('Beta Padel Club')[1], 'foreign@example.com', 'Fay Foreign');
        self::assertSame(404, $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['coach_user_id' => $foreign]))->getStatusCode());
        self::assertSame(404, $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00', ['coach_user_id' => 'nope']))->getStatusCode());
        self::assertSame(0, $this->assignments());
    }

    #[Group('AC-65')]
    public function testTheSameCoachCannotBeAssignedToTheSameEventTwice(): void
    {
        $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00'));
        $again = $this->post('/trainer/assignments', $this->form('2030-03-04', '10:00', '11:00'));

        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('already assigned to this event', $this->body($again));
        self::assertSame(1, $this->assignments());
    }

    #[Group('AC-65')]
    public function testOnlyTheTrainerRoleAssigns(): void
    {
        $this->signInAsCoachUser($this->coach);
        self::assertSame(403, $this->get('/trainer/assignments')->getStatusCode());
        self::assertSame(403, $this->post('/trainer/assignments', $this->form())->getStatusCode());
        self::assertSame(0, $this->assignments());
    }
}
