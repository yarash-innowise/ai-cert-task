<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use PHPUnit\Framework\Attributes\Group;

final class AcceptCoachInvitationTest extends OrganizationHttpTestCase
{
    private string $trainer;
    private string $org;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainer, $this->org] = $this->trainerSignedIn();
        $this->invite('coach@example.com', ['name' => 'Casey Coach']);
        $this->token = $this->tokenFor('coach@example.com');
        $this->signOut();
    }

    private function register(string $name = 'Casey Coach', string $password = self::COACH_PASSWORD): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/invitations/' . $this->token, ['name' => $name, 'password' => $password]);
    }

    #[Group('AC-26')]
    public function testTheLandingPageOfAValidLinkOffersAccountCreationAndWritesNothing(): void
    {
        $before = $this->writeFootprint();

        $response = $this->get('/invitations/' . $this->token);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('Join Ace Tennis Academy as a Coach', $body);
        self::assertStringContainsString('coach@example.com', $body);
        self::assertStringContainsString('Create account and accept', $body);
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-57'), Group('AC-58')]
    public function testANewCoachRegistersByTheLinkAndBecomesAnActiveCoach(): void
    {
        $response = $this->register();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/coach', $this->location($response));
        $user = $this->row("SELECT * FROM users WHERE email = 'coach@example.com'");
        self::assertIsArray($user);
        self::assertSame('coach', $user['role']);
        self::assertSame('active', $user['status']);
        self::assertNotNull($user['email_verified_at']);
        self::assertStringStartsWith('$argon2id$', (string) $user['password_hash']);
        self::assertSame(1, $this->activeMemberships((string) $user['id']));
        self::assertSame($this->org, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :u AND status = 'active'", ['u' => $user['id']]));
        self::assertSame('accepted', $this->invitationStatusOf('coach@example.com'));
        self::assertSame($user['id'], $this->scalar('SELECT accepted_by FROM coach_invitations'));
        // signed in as the new Coach
        self::assertSame(200, $this->get('/coach')->getStatusCode());
        $audit = $this->row("SELECT payload FROM audit_log WHERE action = 'coach.invitation_accepted'");
        self::assertIsArray($audit);
        self::assertStringNotContainsString('coach@example.com', (string) $audit['payload']);
    }

    #[Group('AC-57')]
    public function testAnExistingCoachAcceptsWhileSignedInAsTheInvitedEmail(): void
    {
        $coach = $this->coachUser('coach@example.com');
        $this->signInAsCoach('coach@example.com');

        self::assertStringContainsString('Accept invitation', $this->body($this->get('/invitations/' . $this->token)));
        $response = $this->post('/invitations/' . $this->token);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->activeMemberships($coach));
        self::assertSame('accepted', $this->invitationStatusOf('coach@example.com'));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'coach@example.com'"));
    }

    #[Group('AC-26')]
    public function testACoachSignedInAsAnotherEmailIsRefusedAndNobodyIsAssociated(): void
    {
        $other = $this->coachUser('other@example.com');
        $this->signInAsCoach('other@example.com');
        $before = $this->writeFootprint();

        $page = $this->get('/invitations/' . $this->token);
        $response = $this->post('/invitations/' . $this->token);

        self::assertStringContainsString('sent to a different email address', $this->body($page));
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('sent to a different email address', $this->body($response));
        self::assertSame(0, $this->activeMemberships($other));
        self::assertSame('pending', $this->invitationStatusOf('coach@example.com'));
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-26')]
    public function testANonCoachAccountCannotAcceptACoachInvitation(): void
    {
        $this->createUser('player', 'coach@example.com');
        $this->login('coach@example.com');

        $response = $this->post('/invitations/' . $this->token);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('not a Coach account', $this->body($response));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_memberships'));
    }

    #[Group('AC-26')]
    public function testAGuestWhoseEmailAlreadyHasAnAccountIsToldToSignIn(): void
    {
        $coach = $this->coachUser('coach@example.com');

        $response = $this->register();

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Sign in with it to accept the invitation.', $this->body($response));
        self::assertSame(0, $this->activeMemberships($coach));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'coach@example.com'"));
    }

    #[Group('AC-26')]
    public function testARefusedPasswordCreatesNoAccountAndKeepsTheInvitationOpen(): void
    {
        $response = $this->register('Casey Coach', 'short');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Password must be 10 to 128 characters long.', $this->body($response));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'coach@example.com'"));
        self::assertSame('pending', $this->invitationStatusOf('coach@example.com'));
        self::assertSame(302, $this->register()->getStatusCode());
    }

    #[Group('AC-18'), Group('AC-26')]
    public function testASecondUseOfTheLinkIsRefusedAsAlreadyUsed(): void
    {
        $this->register();
        $coach = (string) $this->scalar("SELECT id FROM users WHERE email = 'coach@example.com'");
        $this->signOut();
        $before = $this->writeFootprint();

        $page = $this->get('/invitations/' . $this->token);
        $again = $this->register();

        self::assertSame(410, $page->getStatusCode());
        self::assertStringContainsString('This invitation has already been used.', $this->body($page));
        self::assertSame(410, $again->getStatusCode());
        self::assertSame(1, $this->activeMemberships($coach));
        self::assertSame($before, $this->writeFootprint());
        // the Trainer sees Accepted
        $this->login((string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $this->trainer]));
        self::assertStringContainsString('<span class="badge">Accepted</span>', $this->body($this->get('/trainer/coaches')));
    }

    #[Group('AC-26')]
    public function testUnknownAndMalformedLinksShowTheSameClearMessage(): void
    {
        $unknown = $this->get('/invitations/' . str_repeat('A', 43));
        $malformed = $this->get('/invitations/nonsense');

        foreach ([$unknown, $malformed] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertStringContainsString('This invitation link is not valid.', $this->body($response));
        }
        self::assertSame(404, $this->post('/invitations/' . str_repeat('A', 43), ['name' => 'X', 'password' => self::COACH_PASSWORD])->getStatusCode());
    }

    #[Group('AC-26')]
    public function testALinkOfADeactivatedTrainerIsNotValid(): void
    {
        $this->pdo->prepare("UPDATE organizations SET status = 'inactive' WHERE id = :o")->execute(['o' => $this->org]);

        $response = $this->get('/invitations/' . $this->token);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(404, $this->register()->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_memberships'));
    }

    #[Group('AC-24')]
    public function testTheAcceptedCoachActsInTheTrainersOrganization(): void
    {
        $this->register();

        $context = $this->row("SELECT 1 AS ok FROM coach_memberships WHERE organization_id = :o AND status = 'active'", ['o' => $this->org]);
        self::assertIsArray($context);
        $options = $this->container->get(\App\Shared\Domain\ContextOptions::class);
        self::assertInstanceOf(\App\Shared\Domain\ContextOptions::class, $options);
        $coach = (string) $this->scalar("SELECT id FROM users WHERE email = 'coach@example.com'");
        $valid = $options->for($coach, 'coach');
        self::assertCount(1, $valid);
        self::assertSame($this->org, $valid[0]->organizationId->value);
    }
}
