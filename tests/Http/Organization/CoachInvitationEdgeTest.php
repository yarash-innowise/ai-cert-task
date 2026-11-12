<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use PHPUnit\Framework\Attributes\Group;

/** Edge-case rows: coach link reused / after 7 days / coach active elsewhere / parallel invites. */
final class CoachInvitationEdgeTest extends OrganizationHttpTestCase
{
    private string $trainerEmail = 'trainer-a@example.com';
    private string $org;

    protected function setUp(): void
    {
        parent::setUp();
        $trainer = $this->createUser('trainer', $this->trainerEmail, name: 'Tina Trainer');
        $this->org = (string) $this->scalar("INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, 'Ace Tennis Academy') RETURNING id", ['o' => $trainer]);
    }

    private function trainerInvites(string $email, ?string $trainerEmail = null): string
    {
        $this->signOut();
        $this->login($trainerEmail ?? $this->trainerEmail);
        $this->invite($email);

        return $this->tokenFor($email);
    }

    private function otherTrainer(string $business = 'Beta Padel Club', string $email = 'trainer-b@example.com'): string
    {
        $trainer = $this->createUser('trainer', $email, name: 'Ben Trainer');

        return (string) $this->scalar('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n) RETURNING id', ['o' => $trainer, 'n' => $business]);
    }

    private function touchSessions(): void
    {
        $this->pdo->prepare('UPDATE sessions SET last_seen_at = :t, created_at = :t')->execute(['t' => $this->clock->now()->format('Y-m-d H:i:s.uP')]);
    }

    #[Group('AC-18')]
    public function testTheLinkIsStillValidOneSecondBeforeSevenDaysEndAndRefusedAfter(): void
    {
        $token = $this->trainerInvites('coach@example.com');
        $this->signOut();

        $this->clock->advance('6 days 23 hours 59 minutes 59 seconds');
        self::assertSame(200, $this->get('/invitations/' . $token)->getStatusCode());

        $this->clock->advance('2 seconds'); // 7 days + 1 second
        $response = $this->get('/invitations/' . $token);
        self::assertSame(410, $response->getStatusCode());
        self::assertStringContainsString('This invitation has expired.', $this->body($response));
        $post = $this->post('/invitations/' . $token, ['name' => 'Casey', 'password' => self::COACH_PASSWORD]);
        self::assertSame(410, $post->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_memberships'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'coach@example.com'"));
    }

    #[Group('AC-60')]
    public function testAnExpiredInvitationCanBeResentAndTheNewLinkWorks(): void
    {
        $old = $this->trainerInvites('coach@example.com');
        $this->clock->advance('7 days 1 second');
        $this->touchSessions();
        $id = (string) $this->scalar("SELECT id FROM coach_invitations WHERE email = 'coach@example.com'");
        self::assertStringContainsString('<span class="badge">Expired</span>', $this->body($this->get('/trainer/coaches')));

        $resend = $this->post('/trainer/coaches/invitations/' . $id . '/resend');

        self::assertSame('/trainer/coaches?notice=resent', $this->location($resend));
        $new = $this->tokenFor('coach@example.com');
        self::assertNotSame($old, $new);
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM coach_invitations WHERE revoked_at IS NOT NULL'));
        self::assertStringContainsString('<span class="badge">Pending</span>', $this->body($this->get('/trainer/coaches')));
        $this->signOut();
        self::assertSame(410, $this->get('/invitations/' . $old)->getStatusCode());
        self::assertStringContainsString('replaced by a newer one', $this->body($this->get('/invitations/' . $old)));
        $accepted = $this->post('/invitations/' . $new, ['name' => 'Casey', 'password' => self::COACH_PASSWORD]);
        self::assertSame(302, $accepted->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM coach_memberships WHERE status = 'active'"));
    }

    #[Group('AC-60')]
    public function testAnAcceptedInvitationCannotBeResent(): void
    {
        $token = $this->trainerInvites('coach@example.com');
        $this->signOut();
        $this->post('/invitations/' . $token, ['name' => 'Casey', 'password' => self::COACH_PASSWORD]);
        $id = (string) $this->scalar("SELECT id FROM coach_invitations WHERE email = 'coach@example.com'");
        $this->signOut();
        $this->login($this->trainerEmail);

        $response = $this->post('/trainer/coaches/invitations/' . $id . '/resend');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
    }

    #[Group('AC-59')]
    public function testACoachActiveUnderAnotherTrainerIsRefusedWithAClearMessage(): void
    {
        $orgB = $this->otherTrainer();
        $coach = $this->coachUser('coach@example.com');
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)")->execute(['o' => $orgB, 'c' => $coach]);
        $token = $this->trainerInvites('coach@example.com');
        $this->signInAsCoach('coach@example.com');

        $response = $this->post('/invitations/' . $token);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('already an active Coach under another Trainer', $this->body($response));
        self::assertSame(1, $this->activeMemberships($coach));
        self::assertSame($orgB, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $coach]));
        self::assertSame('pending', $this->invitationStatusOf('coach@example.com'));
    }

    #[Group('AC-59')]
    public function testOfTwoPendingInvitationsFromTwoTrainersTheFirstAcceptedWins(): void
    {
        $orgB = $this->otherTrainer();
        $coach = $this->coachUser('coach@example.com');
        $tokenA = $this->trainerInvites('coach@example.com');
        $tokenB = $this->trainerInvites('coach@example.com', 'trainer-b@example.com');
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM coach_invitations WHERE email = 'coach@example.com' AND revoked_at IS NULL"), 'both invitations may exist');
        $this->signInAsCoach('coach@example.com');

        $first = $this->post('/invitations/' . $tokenB);
        $second = $this->post('/invitations/' . $tokenA);

        self::assertSame(302, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
        self::assertStringContainsString('already an active Coach under another Trainer', $this->body($second));
        self::assertSame(1, $this->activeMemberships($coach));
        self::assertSame($orgB, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $coach]));
        self::assertSame($this->org, $this->scalar("SELECT organization_id FROM coach_invitations WHERE accepted_at IS NULL"));
    }

    #[Group('AC-10')]
    public function testATrainerCannotResendAnotherTrainersInvitation(): void
    {
        $this->otherTrainer();
        $this->trainerInvites('coach@example.com');
        $id = (string) $this->scalar("SELECT id FROM coach_invitations WHERE email = 'coach@example.com'");
        $this->signOut();
        $this->login('trainer-b@example.com');

        $resend = $this->post('/trainer/coaches/invitations/' . $id . '/resend');
        $unknown = $this->post('/trainer/coaches/invitations/00000000-0000-7000-8000-000000000000/resend');
        $list = $this->body($this->get('/trainer/coaches'));

        self::assertSame(404, $resend->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertStringNotContainsString('coach@example.com', $list);
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
        self::assertNull($this->scalar('SELECT revoked_at FROM coach_invitations'));
    }
}
