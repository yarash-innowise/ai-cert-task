<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class InviteCoachTest extends OrganizationHttpTestCase
{
    private string $trainer;
    private string $org;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainer, $this->org] = $this->trainerSignedIn();
    }

    #[Group('AC-57')]
    public function testTheTrainerInvitesACoachWithOnlyAnEmail(): void
    {
        $response = $this->invite('Coach@Example.com');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/trainer/coaches?notice=invited', $this->location($response));
        $row = $this->row('SELECT * FROM coach_invitations WHERE organization_id = :o', ['o' => $this->org]);
        self::assertIsArray($row);
        self::assertSame('coach@example.com', $row['email']);
        self::assertNull($row['name']);
        self::assertNull($row['message']);
        self::assertSame($this->trainer, $row['created_by']);
        self::assertNull($row['accepted_at']);
    }

    #[Group('AC-57')]
    public function testNameAndMessageAreStoredWhenGiven(): void
    {
        $this->invite('coach@example.com', ['name' => 'Casey', 'message' => 'Join our team!']);

        $row = $this->row('SELECT name, message FROM coach_invitations');
        self::assertSame(['name' => 'Casey', 'message' => 'Join our team!'], $row);
    }

    #[Group('AC-57')]
    public function testAnEmailIsRequiredAndMustBeValid(): void
    {
        $missing = $this->invite('');
        $invalid = $this->invite('not-an-email');

        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('Email is required.', $this->body($missing));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter a valid email address.', $this->body($invalid));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'coach-invitation'"));
    }

    #[Group('AC-57')]
    public function testTheMessageHasALengthLimit(): void
    {
        $response = $this->invite('coach@example.com', ['message' => str_repeat('x', 1001)]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
    }

    #[Group('AC-18'), Group('AC-57')]
    public function testTheLinkIsUniqueSingleUseAndValidForSevenDays(): void
    {
        $this->invite('one@example.com');
        $this->invite('two@example.com');

        $statement = $this->pdo->query('SELECT created_at, expires_at, token_hash FROM coach_invitations ORDER BY email');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $rows = $statement->fetchAll();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['token_hash'], $rows[1]['token_hash']);
        foreach ($rows as $row) {
            self::assertSame('2030-03-08 10:00:00', (new \DateTimeImmutable((string) $row['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        self::assertNotSame($this->tokenFor('one@example.com'), $this->tokenFor('two@example.com'));
        // only the hash is stored, never the token
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_invitations WHERE token_hash = :t', ['t' => $this->tokenFor('one@example.com')]));
    }

    #[Group('AC-57'), Group('AC-86')]
    public function testTheInvitationEmailIsQueuedWithTheLinkAndTheMessage(): void
    {
        $this->invite('coach@example.com', ['name' => 'Casey', 'message' => 'Join our team!']);

        $row = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'coach-invitation'");
        self::assertIsArray($row);
        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('coach@example.com', $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $mail = $renderer->render('coach-invitation', $payload['to'], $payload['context'], 'm1');
        self::assertSame('Ace Tennis Academy invited you to join as a Coach', $mail->subject);
        $url = (string) $payload['context']['accept_url'];
        self::assertStringContainsString('href="' . $url . '"', $mail->html);
        self::assertStringContainsString($url, $mail->text);
        self::assertStringContainsString('Join our team!', $mail->html);
        self::assertStringContainsString('2030-03-08', $mail->text);
    }

    #[Group('AC-58')]
    public function testEachInvitationShowsItsStatusPendingAcceptedOrExpired(): void
    {
        $this->invite('pending@example.com');
        $this->invite('accepted@example.com');
        $this->invite('old@example.com');
        $this->pdo->prepare("UPDATE coach_invitations SET accepted_at = :t WHERE email = 'accepted@example.com'")->execute(['t' => '2030-03-01 10:00:00+00']);
        $this->pdo->prepare("UPDATE coach_invitations SET created_at = :c, expires_at = :e WHERE email = 'old@example.com'")->execute(['c' => '2030-02-01 10:00:00+00', 'e' => '2030-02-08 10:00:00+00']);

        $body = $this->body($this->get('/trainer/coaches'));

        self::assertMatchesRegularExpression('/pending@example\.com.*?<span class="badge">Pending<\/span>/s', $body);
        self::assertMatchesRegularExpression('/accepted@example\.com.*?<span class="badge">Accepted<\/span>/s', $body);
        self::assertMatchesRegularExpression('/old@example\.com.*?<span class="badge">Expired<\/span>/s', $body);
    }

    #[Group('AC-58')]
    public function testAPendingInvitationTurnsExpiredWhenTheSevenDaysPass(): void
    {
        $this->invite('coach@example.com');
        self::assertSame('pending', $this->invitationStatusOf('coach@example.com'));
        $this->clock->advance('7 days');
        $this->pdo->prepare('UPDATE sessions SET last_seen_at = :t, created_at = :t')->execute(['t' => $this->clock->now()->format('Y-m-d H:i:s.uP')]);

        self::assertStringContainsString('<span class="badge">Expired</span>', $this->body($this->get('/trainer/coaches')));
    }

    #[Group('AC-9')]
    public function testOnlyATrainerCanInvite(): void
    {
        foreach (['coach', 'player'] as $role) {
            $this->signInAs($role);
            self::assertSame(403, $this->invite('x@example.com')->getStatusCode(), $role);
            self::assertSame(403, $this->get('/trainer/coaches')->getStatusCode(), $role);
        }
        $this->signOut();
        self::assertSame(302, $this->get('/trainer/coaches')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_invitations'));
    }

    #[Group('AC-57')]
    public function testInvitingAnExistingCoachOfThisTrainerIsRefused(): void
    {
        $coach = $this->coachUser('coach@example.com');
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)")->execute(['o' => $this->org, 'c' => $coach]);

        $response = $this->invite('coach@example.com');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('already one of your coaches', $this->body($response));
    }

    #[Group('AC-57')]
    public function testInvitingTheSameEmailAgainReplacesThePendingInvitation(): void
    {
        $this->invite('coach@example.com');
        $first = $this->tokenFor('coach@example.com');
        $this->invite('coach@example.com');

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM coach_invitations WHERE revoked_at IS NULL'));
        self::assertNotSame($first, $this->tokenFor('coach@example.com'));
        $this->signOut();
        self::assertSame(410, $this->get('/invitations/' . $first)->getStatusCode());
    }
}
