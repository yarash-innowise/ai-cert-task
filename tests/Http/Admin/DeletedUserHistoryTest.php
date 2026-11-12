<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

/** History and analytics survive deletion (AC-77): rows stay, names read "Deleted User", totals do not move. */
final class DeletedUserHistoryTest extends LifecycleTestCase
{
    /** @return array<string, int> the row counts analytics totals are built from (players, associations, requests, attendance-like rows, users) */
    private function totals(): array
    {
        $counts = [];
        foreach (['users', 'player_profiles', 'player_trainer_associations', 'approval_requests', 'organizations', 'coach_memberships'] as $table) {
            $counts[$table] = (int) $this->scalar('SELECT count(*) FROM ' . $table);
        }
        $counts['active_associations'] = (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE ended_at IS NULL');
        $counts['revenue_minor'] = (int) $this->scalar("SELECT coalesce(sum(amount_minor), 0) FROM approval_requests WHERE status = 'approved'");

        return $counts;
    }

    #[Group('AC-77')]
    public function testHistoricalRowsAndTotalsAreUnchangedAndRostersShowDeletedUser(): void
    {
        $self = $this->selfOf($this->parent);
        $this->associate($self, $this->org);
        $this->openRequest('purchase', 'approved', self::EVENT_2);
        $this->openRequest('rsvp', 'pending', '0190aaaa-0000-7000-8000-0000000000e5', payment: 'none', amount: 0);
        $before = $this->totals();

        $this->deleteUser($this->parent);

        $after = $this->totals();
        // The parent's deletion ends the associations of their child profiles (Q-01.14, amended): the rows stay as
        // history, only Mia's active one (the self profile stays active here) is no longer active.
        self::assertSame($before['active_associations'] - 1, $after['active_associations']);
        unset($before['active_associations'], $after['active_associations']);
        self::assertSame($before, $after);
        $this->signInWith($this->trainerEmail());
        $roster = $this->body($this->get('/trainer/players'));
        self::assertStringContainsString('Deleted User', $roster);
        self::assertStringNotContainsString('Pat Parent', $roster);
        self::assertStringNotContainsString('Pat ', $roster);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM approval_requests WHERE parent_user_id = :p AND status = 'approved'", ['p' => $this->parent]), 'the request still resolves to the deleted parent');
    }

    #[Group('AC-77')]
    public function testTheUsersToolStillListsTheDeletedUserAsDeletedWithoutActions(): void
    {
        $this->deleteUser($this->parent);

        $html = $this->body($this->get('/admin/users?status=deleted'));

        self::assertStringContainsString('Deleted User', $html);
        self::assertStringContainsString('deleted_' . $this->parent . '@example.com', $html);
        self::assertStringContainsString('badge--deleted', $html);
        self::assertStringNotContainsString('/admin/users/' . $this->parent . '/', $html, 'no action is offered for a deleted user');
    }

    #[Group('AC-77')]
    public function testADeletedCoachStaysOnTheTrainersRosterAsDeletedUser(): void
    {
        $coach = $this->activeCoach();
        $this->deactivate($coach);
        $this->deleteUser($coach);
        $this->signInWith($this->trainerEmail());

        $html = $this->body($this->get('/trainer/coaches'));

        self::assertStringContainsString('Deleted User', $html);
        self::assertStringNotContainsString('Cora Coach', $html);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM coach_memberships WHERE coach_user_id = :c", ['c' => $coach]));
    }
}
