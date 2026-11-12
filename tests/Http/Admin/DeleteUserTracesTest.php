<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting a user leaves no personal data in any table, and a deleted parent takes the child profiles and child
 * logins with them (AC-76, AC-77, AC-79; Q-01.13, Q-01.14 as amended 2026-11-12). Rows stay, so counts do not move.
 */
final class DeleteUserTracesTest extends LifecycleTestCase
{
    private const NEEDLES = ['parent@example.com', 'kid@example.com', 'Pat Parent', 'Mia Smith', 'Leo Smith', 'Kid Login'];
    private const TABLES = ['users', 'player_profiles', 'coach_invitations', 'outbox', 'notifications', 'registration_prefills', 'login_attempts',
        'approval_requests', 'coach_assignments', 'coach_availability_overrides', 'user_profiles'];

    private string $leo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("UPDATE users SET name = 'Kid Login' WHERE id = :i")->execute(['i' => $this->kid]);
        $this->leo = $this->childOf($this->parent, 'Leo', 'Smith', '2016-02-02');
        $this->associate($this->leo, $this->org);
    }

    private function assertNoTrace(string $needle): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM {$table} t WHERE t::text ILIKE :n", ['n' => '%' . $needle . '%']), "{$table} still holds {$needle}");
        }
    }

    private function assertNoTraceAnywhere(): void
    {
        foreach (self::NEEDLES as $needle) {
            $this->assertNoTrace($needle);
        }
    }

    #[Group('AC-76'), Group('AC-79')]
    public function testInvitationsAttemptsPrefillsAndQueuedEmailsLeaveNoTraceOfTheOriginalEmail(): void
    {
        $this->pdo->prepare("INSERT INTO coach_invitations (organization_id, email, name, message, token_hash, created_by, expires_at) VALUES (:o, 'parent@example.com', 'Pat Parent', 'Hi Pat Parent, join us', 'h1', :t, '2031-01-01+00')")->execute(['o' => $this->org, 't' => $this->trainer]);
        $this->pdo->prepare("INSERT INTO coach_invitations (organization_id, email, name, message, token_hash, created_by, expires_at, accepted_by, accepted_at) VALUES (:o, 'other@example.com', 'Pat Parent', 'accepted', 'h2', :t, '2031-01-01+00', :p, now())")->execute(['o' => $this->org, 't' => $this->trainer, 'p' => $this->parent]);
        $this->pdo->prepare("INSERT INTO coach_invitations (organization_id, email, name, message, token_hash, created_by, expires_at) VALUES (:o, 'bystander@example.com', 'Bea', 'Hello', 'h3', :t, '2031-01-01+00')")->execute(['o' => $this->org, 't' => $this->trainer]);
        $this->pdo->prepare("INSERT INTO login_attempts (email, ip, succeeded) VALUES ('Parent@Example.com', '203.0.113.5', false), ('kid@example.com', '203.0.113.6', true), ('bystander@example.com', '203.0.113.7', false)")->execute();
        $this->pdo->prepare("INSERT INTO registration_prefills (organization_id, token_hash, data, expires_at) VALUES (:o, :h1, '{\"email\": \"Parent@example.com\", \"first_name\": \"Pat\"}', '2031-01-01+00'), (:o, :h2, '{\"email\": \"bystander@example.com\"}', '2031-01-01+00')")
            ->execute(['o' => $this->org, 'h1' => str_repeat('a', 64), 'h2' => str_repeat('b', 64)]);
        $this->queueEmail('Parent@Example.com', 'pending', ['recipient_name' => 'Pat Parent']);
        $this->queueEmail('kid@example.com', 'processing', ['recipient_name' => 'Kid Login']);
        $this->queueEmail('trainer@example.com', 'failed', ['child_name' => 'Mia Smith']);
        $this->queueEmail('bystander@example.com', 'pending', ['recipient_name' => 'Bea']);
        $invitations = (int) $this->scalar('SELECT count(*) FROM coach_invitations');

        $this->deleteUser($this->parent);

        $this->assertNoTraceAnywhere();
        self::assertSame($invitations, (int) $this->scalar('SELECT count(*) FROM coach_invitations'), 'invitation rows are kept');
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM coach_invitations WHERE email LIKE 'deleted\\_%@example.com' AND name IS NULL AND message IS NULL"));
        self::assertSame('Hello', $this->scalar("SELECT message FROM coach_invitations WHERE token_hash = 'h3'"), 'a bystander invitation is untouched');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM login_attempts WHERE email = 'bystander@example.com'"));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM registration_prefills'), 'only the bystander prefill remains');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND payload->>'to' = 'bystander@example.com'"), 'queued mail to someone else is kept');
    }

    /** @param array<string, string> $context */
    private function queueEmail(string $to, string $status, array $context): void
    {
        $this->pdo->prepare("INSERT INTO outbox (kind, topic, payload, status) VALUES ('email', 'password-reset', CAST(:p AS jsonb), :s)")
            ->execute(['p' => json_encode(['to' => $to, 'context' => $context], JSON_THROW_ON_ERROR), 's' => $status]);
    }

    #[Group('AC-76')]
    public function testOwnNotificationsGoAndWhatOthersWereToldAboutThemIsBlanked(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO notifications (user_id, kind, title, body, payload) VALUES (:u, 'k', :t, :b, CAST(:p AS jsonb))");
        $insert->execute(['u' => $this->parent, 't' => 'Mia Smith asks for your approval', 'b' => 'Pat Parent', 'p' => '{}']);
        $insert->execute(['u' => $this->kid, 't' => 'Your parent Pat Parent decided', 'b' => '', 'p' => '{}']);
        $insert->execute(['u' => $this->trainer, 't' => 'Mia Smith spent 3 tokens', 'b' => 'Mia Smith joined', 'p' => json_encode(['profile_id' => $this->mia])]);
        $insert->execute(['u' => $this->trainer, 't' => 'Leo Smith joined', 'b' => 'Pat Parent', 'p' => json_encode(['profile_id' => $this->leo])]);
        $request = $this->openRequest();
        $insert->execute(['u' => $this->trainer, 't' => 'Request from Mia Smith', 'b' => 'Pat Parent', 'p' => json_encode(['request_id' => $request])]);
        $insert->execute(['u' => $this->trainer, 't' => 'Unrelated news', 'b' => 'Nothing personal', 'p' => '{}']);

        $this->deleteUser($this->parent);

        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM notifications WHERE user_id IN (:p, :k)', ['p' => $this->parent, 'k' => $this->kid]));
        $this->assertNoTraceAnywhere();
        self::assertSame(3, (int) $this->scalar("SELECT count(*) FROM notifications WHERE title = 'Notification about a deleted user'"), 'the rows of the trainer that named them are kept, blanked');
        self::assertSame('Nothing personal', $this->scalar("SELECT body FROM notifications WHERE title = 'Unrelated news'"));
    }

    #[Group('AC-76'), Group('AC-77')]
    public function testFreeTextTheUserAuthoredIsRemovedAndTheRowsStay(): void
    {
        $request = $this->openRequest();
        $this->pdo->prepare("UPDATE approval_requests SET child_note = 'Mia Smith wants this', parent_note = 'Pat Parent says no' WHERE id = :i")->execute(['i' => $request]);
        $coach = $this->activeCoach('coach@example.com', 'Cora Coach');
        $override = (string) $this->scalar("INSERT INTO coach_availability_overrides (organization_id, event_ref, coach_user_id, reason, overridden_by) VALUES (:o, 'E1', :c, 'Cora Coach is on holiday', :t) RETURNING id", ['o' => $this->org, 'c' => $coach, 't' => $this->trainer]);
        $this->pdo->prepare("INSERT INTO coach_assignments (organization_id, event_ref, event_date, starts_at, ends_at, coach_user_id, assigned_by, change_note, override_id) VALUES (:o, 'E1', '2030-04-01', '10:00', '11:00', :c, :t, 'Cora Coach cannot make it', :ov)")
            ->execute(['o' => $this->org, 'c' => $coach, 't' => $this->trainer, 'ov' => $override]);
        $assignmentRows = (int) $this->scalar('SELECT count(*) FROM coach_assignments');

        $this->deleteUser($this->parent);
        $this->deleteUser($coach);

        self::assertNull($this->scalar('SELECT child_note FROM approval_requests WHERE id = :i', ['i' => $request]));
        self::assertNull($this->scalar('SELECT parent_note FROM approval_requests WHERE id = :i', ['i' => $request]));
        self::assertNull($this->scalar("SELECT change_note FROM coach_assignments WHERE event_ref = 'E1'"));
        self::assertSame('[removed]', $this->scalar('SELECT reason FROM coach_availability_overrides WHERE id = :i', ['i' => $override]));
        self::assertSame($assignmentRows, (int) $this->scalar('SELECT count(*) FROM coach_assignments'));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM approval_requests WHERE id = :i', ['i' => $request]));
        $this->assertNoTrace('Cora Coach');
        $this->assertNoTraceAnywhere();
    }

    #[Group('AC-76'), Group('AC-77'), Group('AC-79')]
    public function testDeletingAParentAnonymizesTheChildrenAndChildLoginsAndEndsTheirAssociationsKeepingHistory(): void
    {
        $this->pdo->prepare("UPDATE player_profiles SET school = 'Lincoln High', jersey_number = 9 WHERE id IN (:a, :b)")->execute(['a' => $this->mia, 'b' => $this->leo]);
        $this->pdo->prepare("UPDATE users SET phone = '+14155552671' WHERE id = :i")->execute(['i' => $this->kid]);
        $this->openRequest('purchase', 'approved', self::EVENT_2);
        $totals = $this->counts();
        $activeBefore = $this->activeAssociations($this->mia) + $this->activeAssociations($this->leo);
        self::assertSame(2, $activeBefore);

        $this->deleteUser($this->parent);

        foreach ([$this->mia, $this->leo] as $child) {
            $row = $this->row('SELECT * FROM player_profiles WHERE id = :i', ['i' => $child]);
            self::assertIsArray($row);
            self::assertSame('Deleted', $row['first_name']);
            self::assertSame('User', $row['last_name']);
            foreach (['birth_date', 'school', 'jersey_number', 'photo_path'] as $column) {
                self::assertNull($row[$column], $column);
            }
            self::assertSame(0, $this->activeAssociations($child), 'the Trainer association ended');
            self::assertSame('guardian_deleted', $this->scalar('SELECT end_reason FROM player_trainer_associations WHERE player_profile_id = :p', ['p' => $child]));
            self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NOT NULL', ['p' => $child]), 'history kept');
        }
        $kid = $this->row('SELECT * FROM users WHERE id = :i', ['i' => $this->kid]);
        self::assertIsArray($kid);
        self::assertSame('Deleted User', $kid['name']);
        self::assertSame('deleted_' . $this->kid . '@example.com', $kid['email']);
        self::assertSame('deleted', $kid['status']);
        self::assertNull($kid['phone']);
        self::assertNull($kid['password_hash']);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->kid]));
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'job' AND topic = 'event_registrations.cancel_upcoming' AND payload->>'profile_id' IN (:a, :b)", ['a' => $this->mia, 'b' => $this->leo]), 'the RSVP cancellation is queued per association');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'job' AND topic = 'availability.purge' AND payload->'subject_ids' @> CAST(:i AS jsonb)", ['i' => json_encode([$this->leo])]), 'children availability goes through the purge job');
        self::assertSame($totals, $this->counts());
        $this->assertNoTraceAnywhere();

        $audit = $this->row("SELECT * FROM audit_log WHERE action = 'user.deleted' AND subject_id = :i", ['i' => $this->parent]);
        self::assertIsArray($audit);
        self::assertStringNotContainsString('parent@example.com', (string) json_encode($audit));
    }

    /** @return array<string, int> rows analytics totals are built from */
    private function counts(): array
    {
        $counts = [];
        foreach (['users', 'player_profiles', 'player_trainer_associations', 'approval_requests'] as $table) {
            $counts[$table] = (int) $this->scalar('SELECT count(*) FROM ' . $table);
        }

        return $counts;
    }
}
