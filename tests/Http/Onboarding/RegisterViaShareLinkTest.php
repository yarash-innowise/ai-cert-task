<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Family\Application\TrainerRoster;
use App\Identity\Domain\PasswordHasher;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class RegisterViaShareLinkTest extends OnboardingHttpTestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Pat Parent',
            'email' => 'pat@example.com',
            'password' => 'a long enough passphrase',
            'phone' => '+1 415 555 2671',
            'player_for' => 'child',
            'player_name' => 'Mia Parent',
            'birth_date' => '2022-05-01',
            'gender' => 'female',
            ...$overrides,
        ];
    }

    /** @return array{string, string, string} organization id, link id, code */
    private function trainerLink(): array
    {
        [, $org] = $this->trainerWithOrganization();
        [$linkId, $code] = $this->shareLink($org);

        return [$org, $linkId, $code];
    }

    #[Group('AC-19')]
    public function testAVisitorWhoIsNotSignedInSeesRegisterAndSignInChoices(): void
    {
        [, , $code] = $this->trainerLink();

        $landing = $this->body($this->get('/join/' . $code));
        $form = $this->get('/join/' . $code . '/register');

        self::assertStringContainsString('/join/' . $code . '/register', $landing);
        self::assertStringContainsString('/login?return_to=', $landing);
        self::assertSame(200, $form->getStatusCode());
        foreach (['name="name"', 'name="email"', 'name="password"', 'name="phone"', 'name="player_name"', 'name="birth_date"', 'name="gender"', 'name="player_for"'] as $field) {
            self::assertStringContainsString($field, $this->body($form));
        }
    }

    #[Group('AC-19'), Group('AC-20')]
    public function testRegisteringCreatesAccountProfileAssociationAndRosterRow(): void
    {
        [$org, $linkId, $code] = $this->trainerLink();

        $response = $this->post('/join/' . $code . '/register', $this->form());

        self::assertSame(200, $response->getStatusCode());
        $user = $this->row('SELECT * FROM users WHERE email = :e', ['e' => 'pat@example.com']);
        self::assertIsArray($user);
        self::assertSame('player', $user['role']);
        self::assertSame('standard', $user['account_kind']);
        self::assertNull($user['email_verified_at']);
        self::assertSame('+14155552671', $user['phone']);
        self::assertTrue((new PasswordHasher())->verify('a long enough passphrase', (string) $user['password_hash']));
        $profile = $this->row("SELECT * FROM player_profiles WHERE owner_user_id = :o AND kind = 'child'", ['o' => $user['id']]);
        self::assertIsArray($profile);
        self::assertSame('Mia', $profile['first_name']);
        self::assertSame('female', $profile['gender']);
        $association = $this->row('SELECT * FROM player_trainer_associations WHERE player_profile_id = :p', ['p' => $profile['id']]);
        self::assertSame($org, $association['organization_id'] ?? null);
        self::assertSame($linkId, $association['source_share_link_id'] ?? null);
        $roster = $this->container->get(TrainerRoster::class);
        self::assertInstanceOf(TrainerRoster::class, $roster);
        self::assertSame(['Mia Parent'], array_map(static fn($p): string => $p->fullName(), $roster->forOrganization(OrganizationId::fromString($org))));
    }

    #[Group('AC-20'), Group('AC-86')]
    public function testAConfirmationAndAVerificationEmailAreQueued(): void
    {
        [, , $code] = $this->trainerLink();

        $this->post('/join/' . $code . '/register', $this->form());

        $confirmation = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'registration-confirmation'");
        self::assertIsArray($confirmation);
        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $confirmation['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pat@example.com', $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $mail = $renderer->render('registration-confirmation', $payload['to'], $payload['context'], 'm1');
        self::assertSame('Welcome to Ace Tennis Academy', $mail->subject);
        self::assertStringContainsString('Mia Parent', $mail->text);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND topic = 'email-verification'"));
    }

    #[Group('AC-23')]
    public function testTheLinkUseRecordsTheRegistration(): void
    {
        [, $linkId, $code] = $this->trainerLink();

        $this->post('/join/' . $code . '/register', $this->form());

        $use = $this->row('SELECT * FROM share_link_uses');
        self::assertSame($linkId, $use['share_link_id'] ?? null);
        self::assertSame('registered', $use['outcome'] ?? null);
        self::assertSame($this->scalar('SELECT id FROM users WHERE email = :e', ['e' => 'pat@example.com']), $use['user_id'] ?? null);
        self::assertNotNull($use['player_profile_id'] ?? null);
        self::assertSame(1, $this->scalar('SELECT use_count FROM share_links WHERE id = :i', ['i' => $linkId]));
        self::assertSame('2030-03-01 10:00:00+00', $this->scalar('SELECT last_used_at FROM share_links WHERE id = :i', ['i' => $linkId]));
    }

    #[Group('AC-19')]
    public function testAnAdultCanRegisterThemselvesAndGetsAMeProfile(): void
    {
        [, , $code] = $this->trainerLink();

        $this->post('/join/' . $code . '/register', $this->form(['player_for' => 'me', 'player_name' => '', 'birth_date' => '1990-04-02']));

        $user = (string) $this->scalar('SELECT id FROM users WHERE email = :e', ['e' => 'pat@example.com']);
        $profile = $this->row("SELECT first_name, last_name, kind FROM player_profiles WHERE owner_user_id = :o", ['o' => $user]);
        self::assertSame(['first_name' => 'Pat', 'last_name' => 'Parent', 'kind' => 'self'], $profile);
    }

    #[Group('AC-19')]
    public function testUnder18CannotRegisterThemselvesAndAnAdultIsNotAChild(): void
    {
        [, , $code] = $this->trainerLink();
        $before = $this->writeFootprint();

        $teen = $this->post('/join/' . $code . '/register', $this->form(['player_for' => 'me', 'birth_date' => '2014-01-01']));
        $adultChild = $this->post('/join/' . $code . '/register', $this->form(['player_for' => 'child', 'birth_date' => '1990-01-01']));

        self::assertSame(422, $teen->getStatusCode());
        self::assertStringContainsString('at least 18', $this->body($teen));
        self::assertSame(422, $adultChild->getStatusCode());
        self::assertStringContainsString('must be between 0 and 17', $this->body($adultChild));
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-19')]
    public function testMissingFieldsAreNamedAndPasswordIsNotEchoed(): void
    {
        [, , $code] = $this->trainerLink();

        $response = $this->post('/join/' . $code . '/register', ['name' => '', 'email' => 'nope', 'password' => 'secret-password-xyz', 'phone' => '', 'player_for' => '', 'birth_date' => '', 'gender' => '']);

        $body = $this->body($response);
        self::assertSame(422, $response->getStatusCode());
        foreach (['Your name is required.', 'Enter a valid email address.', 'Phone number is required.', 'Choose who will train.', 'Birth date is required.', 'Choose a gender.'] as $message) {
            self::assertStringContainsString($message, $body);
        }
        self::assertStringNotContainsString('secret-password-xyz', $body);
    }

    #[Group('AC-6')]
    public function testADuplicateEmailInAnyLetterCaseIsRefusedAndNothingIsCreated(): void
    {
        [, , $code] = $this->trainerLink();
        $this->createUser('player', 'pat@example.com');
        $before = $this->writeFootprint();

        foreach (['pat@example.com', 'PAT@Example.COM', '  Pat@example.com '] as $email) {
            $response = $this->post('/join/' . $code . '/register', $this->form(['email' => $email]));

            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('This email is already registered', $this->body($response));
        }
        self::assertSame($before, $this->writeFootprint());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'registration-confirmation'"));
    }

    #[Group('AC-6')]
    public function testASecondRegistrationWithTheSameEmailInAnotherCaseAddsNothing(): void
    {
        [, , $code] = $this->trainerLink();
        $before = $this->writeFootprint();

        $this->post('/join/' . $code . '/register', $this->form());
        $second = $this->post('/join/' . $code . '/register', $this->form(['email' => 'PAT@example.com']));

        self::assertSame(422, $second->getStatusCode());
        self::assertSame($before['users'] + 1, $this->writeFootprint()['users']);
        self::assertSame($before['player_profiles'] + 1, $this->writeFootprint()['player_profiles']);
    }

    #[Group('AC-26')]
    public function testAnInvalidLinkCreatesNothing(): void
    {
        [, , $code] = $this->trainerLink();
        $deactivated = $this->shareLink($this->trainerWithOrganization('Other')[1], null, false)[1];
        $before = $this->writeFootprint();

        foreach (['0000000000000000000000', 'short', $deactivated] as $bad) {
            self::assertSame(404, $this->get('/join/' . $bad . '/register')->getStatusCode());
            $response = $this->post('/join/' . $bad . '/register', $this->form());
            self::assertSame(404, $response->getStatusCode());
            self::assertStringContainsString('This link is not valid', $this->body($response));
        }
        self::assertSame($before, $this->writeFootprint());
        self::assertNotSame('', $code);
    }

    #[Group('AC-19')]
    public function testASignedInUserOpeningTheRegistrationIsSentBackToTheLandingPage(): void
    {
        [, , $code] = $this->trainerLink();
        $this->signInAs('player');

        $response = $this->get('/join/' . $code . '/register');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/join/' . $code, $this->location($response));
    }

    #[Group('AC-19')]
    public function testARegisteredUserMustVerifyTheEmailBeforeSigningIn(): void
    {
        [, , $code] = $this->trainerLink();
        $this->post('/join/' . $code . '/register', $this->form());
        $this->cookies = [];

        $login = $this->login('pat@example.com', 'a long enough passphrase');

        self::assertSame(403, $login->getStatusCode());
    }

    #[Group('perf'), Group('AC-88')]
    public function testShareLinkRegistrationCompletesInUnderTwoSecondsWithProductionHashing(): void
    {
        [, , $code] = $this->trainerLink();
        \assert($this->container instanceof \DI\Container);
        $this->container->set(PasswordHasher::class, new PasswordHasher());

        $started = hrtime(true);
        $response = $this->post('/join/' . $code . '/register', $this->form());
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(2.0, $seconds, sprintf('Registration took %.3f s', $seconds));
    }
}
