<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** Continuing past the schedule warning needs a reason that is not blank (AC-65, edge case). */
final class OverrideReasonTest extends AssignmentHttpTestCase
{
    /** @return iterable<string, array{string}> */
    public static function blankReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['     '];
        yield 'tabs and newlines' => ["\t \n "];
    }

    #[Group('AC-65')]
    #[\PHPUnit\Framework\Attributes\DataProvider('blankReasons')]
    public function testABlankReasonIsRefusedAndTheAssignmentStaysUnconfirmed(string $reason): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['continue_anyway' => '1', 'reason' => $reason]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('A reason is required to continue.', $this->body($response));
        self::assertStringContainsString('is not available at this time per their schedule', html_entity_decode($this->body($response)), 'the warning stays on screen');
        self::assertSame(0, $this->assignments());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_availability_overrides'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'coach.availability_override'"));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM notifications WHERE user_id = :c', ['c' => $this->coach]));
    }

    #[Group('AC-65')]
    public function testAMissingReasonFieldIsRefusedToo(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['continue_anyway' => '1']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->assignments());
    }

    #[Group('AC-65')]
    public function testAReasonThatIsTooLongIsRefused(): void
    {
        $response = $this->post('/trainer/assignments', $this->form('2030-03-05', '10:00', '11:00', ['continue_anyway' => '1', 'reason' => str_repeat('x', 501)]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->assignments());
    }

    #[Group('AC-65')]
    public function testTheDatabaseRefusesABlankReasonEvenIfTheApplicationDidNot(): void
    {
        $this->pdo->exec('SAVEPOINT r1');
        try {
            $this->pdo->prepare("INSERT INTO coach_availability_overrides (organization_id, event_ref, coach_user_id, reason, overridden_by) VALUES (:o, 'E', :c, '   ', :t)")
                ->execute(['o' => $this->org, 'c' => $this->coach, 't' => $this->trainer]);
            self::fail('a blank reason must be refused');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT r1');
    }
}
