<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Approval\Application\AutoDenyForGuardian;
use PHPUnit\Framework\Attributes\Group;

/** Edge case: parent deactivated or deleted with pending requests. */
final class AutoDenyTest extends ApprovalHttpTestCase
{
    private function autoDeny(?string $guardian = null): int
    {
        $job = $this->container->get(AutoDenyForGuardian::class);
        self::assertInstanceOf(AutoDenyForGuardian::class, $job);

        return $job->execute($guardian ?? $this->parent);
    }

    private function statusOf(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $id]);
    }

    #[Group('AC-44'), Group('AC-45')]
    public function testDeactivatingTheGuardianDeniesEveryOpenRequestAutomatically(): void
    {
        $pending = $this->openRequest();
        $info = $this->openRequest('rsvp', 'info_requested', self::EVENT_2, payment: 'none', amount: 0);
        $approved = $this->openRequest('purchase', 'approved', '0190aaaa-0000-7000-8000-0000000000e3');
        $denied = $this->openRequest('purchase', 'denied', '0190aaaa-0000-7000-8000-0000000000e4');
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :p")->execute(['p' => $this->parent]);

        self::assertSame(2, $this->autoDeny());

        self::assertSame('auto_denied', $this->statusOf($pending));
        self::assertSame('auto_denied', $this->statusOf($info));
        self::assertSame('approved', $this->statusOf($approved), 'already decided requests are not touched');
        self::assertSame('denied', $this->statusOf($denied));
        self::assertNotNull($this->scalar('SELECT decided_at FROM approval_requests WHERE id = :i', ['i' => $pending]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-44')]
    public function testTheChildIsToldByEmailAndInTheApp(): void
    {
        $this->openRequest();
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :p")->execute(['p' => $this->parent]);

        $this->autoDeny();

        $note = $this->row("SELECT title, body FROM notifications WHERE kind = 'approval_auto_denied' AND user_id = :u", ['u' => $this->kid]);
        self::assertIsArray($note);
        self::assertStringContainsString('denied automatically', (string) $note['title']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND topic = 'approval-outcome' AND payload->>'to' = :e", ['e' => self::KID_EMAIL]));
        // the deactivated parent keeps the in-app record but gets no email
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_auto_denied' AND user_id = :u", ['u' => $this->parent]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND payload->>'to' = :e", ['e' => self::PARENT_EMAIL]));
    }

    #[Group('AC-45')]
    public function testAnAutoDeniedRequestCannotBeDecidedAfterwards(): void
    {
        $id = $this->openRequest();
        $this->autoDeny();
        $this->signInAsParent();

        $response = $this->post('/family/approvals/' . $id . '/decision', ['decision' => 'approve']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('auto_denied', $this->statusOf($id));
        $this->signInAsKid();
        self::assertStringContainsString('Denied', $this->body($this->get('/family/requests')));
    }

    #[Group('AC-45')]
    public function testOtherParentsRequestsAreUntouched(): void
    {
        $mine = $this->openRequest();
        $other = $this->createUser('player', 'other@example.com', name: 'Olga Other');
        $zed = $this->childOf($other, 'Zed', 'Zebra');
        $this->associate($zed, $this->org);
        $zedKid = $this->scalar(
            "INSERT INTO users (email, name, role, account_kind, guardian_user_id) VALUES ('zed@example.com', 'Zed', 'player', 'child', :g) RETURNING id",
            ['g' => $other],
        );
        $foreign = (string) $this->scalar(
            "INSERT INTO approval_requests (organization_id, child_profile_id, child_user_id, parent_user_id, kind, event_ref, payment_type, expires_at, created_at)
             VALUES (:o, :p, :c, :par, 'rsvp', :e, 'none', CAST('2030-03-03 10:00:00+00' AS timestamptz), CAST('2030-03-01 10:00:00+00' AS timestamptz)) RETURNING id",
            ['o' => $this->org, 'p' => $zed, 'c' => $zedKid, 'par' => $other, 'e' => self::EVENT],
        );

        self::assertSame(1, $this->autoDeny());

        self::assertSame('auto_denied', $this->statusOf($mine));
        self::assertSame('pending', $this->statusOf($foreign));
    }

    #[Group('AC-44')]
    public function testAGuardianWithoutOpenRequestsChangesNothing(): void
    {
        self::assertSame(0, $this->autoDeny());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM notifications'));
    }
}
