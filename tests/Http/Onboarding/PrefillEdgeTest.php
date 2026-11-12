<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Integration\Application\RegistrationPrefillWriter;
use App\Shared\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Group;

/** Edge rows of the spec: camp-form email already registered; tampered or expired link. */
final class PrefillEdgeTest extends OnboardingHttpTestCase
{
    /** @return array{string, string} organization id and token */
    private function prefill(string $email = 'pat@example.com'): array
    {
        [, $org] = $this->trainerWithOrganization();
        $writer = $this->container->get(RegistrationPrefillWriter::class);
        self::assertInstanceOf(RegistrationPrefillWriter::class, $writer);
        $token = $writer->write(OrganizationId::fromString($org), ['name' => 'Pat Parent', 'email' => $email, 'phone' => '+14155552671', 'player_for' => 'me', 'birth_date' => '1990-04-02', 'gender' => 'other']);

        return [$org, $token];
    }

    #[Group('AC-85')]
    public function testAnExistingEmailIsAskedToLogInAndNoDuplicateAccountIsCreated(): void
    {
        [, $token] = $this->prefill();
        $this->createUser('player', 'pat@example.com');
        $before = $this->writeFootprint();

        $form = $this->get('/register/prefill/' . $token);
        $submit = $this->post('/register/prefill/' . $token, ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'a long enough passphrase', 'phone' => '+14155552671', 'player_for' => 'me', 'player_name' => '', 'birth_date' => '1990-04-02', 'gender' => 'other']);

        foreach ([$form, $submit] as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('/login?return_to=' . rawurlencode('/register/prefill/' . $token), $this->body($response));
        }
        self::assertSame($before, $this->writeFootprint());
        self::assertNull($this->scalar('SELECT consumed_at FROM registration_prefills'));
    }

    #[Group('AC-85')]
    public function testThePreFillIsAttachedOnlyAfterLoginToTheExistingAccount(): void
    {
        [$org, $token] = $this->prefill();
        $user = $this->createUser('player', 'pat@example.com');
        $profile = $this->selfOf($user);
        $this->get('/register/prefill/' . $token);
        self::assertSame(0, $this->activeAssociations($profile), 'nothing is attached before login');

        $this->login('pat@example.com');
        $response = $this->post('/register/prefill/' . $token . '/attach');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/family/events', $this->location($response));
        self::assertSame($org, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $profile]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'pat@example.com'"));
        self::assertNotNull($this->scalar('SELECT consumed_at FROM registration_prefills'));
    }

    #[Group('AC-85'), Group('AC-10')]
    public function testSomeoneElsesAccountCannotUseTheLink(): void
    {
        [, $token] = $this->prefill();
        $other = $this->createUser('player', 'other@example.com');
        $profile = $this->selfOf($other);
        $this->login('other@example.com');

        $response = $this->get('/register/prefill/' . $token);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->activeAssociations($profile));
        self::assertNull($this->scalar('SELECT consumed_at FROM registration_prefills'));
    }

    #[Group('AC-85')]
    public function testATamperedTokenGivesABlankOrdinaryForm(): void
    {
        [, $token] = $this->prefill();
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        foreach ([$tampered, 'short', str_repeat('x', 200)] as $bad) {
            $response = $this->get('/register/prefill/' . $bad);
            $body = preg_replace('/\s+/', ' ', $this->body($response)) ?? '';

            self::assertSame(200, $response->getStatusCode());
            self::assertStringNotContainsString('pat@example.com', $body);
            self::assertStringContainsString('name="email" type="email" value=""', $body);
        }
    }

    #[Group('AC-85')]
    public function testAnExpiredTokenGivesABlankOrdinaryFormAndAssignsNothing(): void
    {
        [, $token] = $this->prefill();
        $this->clock->set(new \DateTimeImmutable('2030-03-16 10:00:01+00'));

        $body = preg_replace('/\s+/', ' ', $this->body($this->get('/register/prefill/' . $token))) ?? '';
        $this->post('/register/prefill/' . $token, ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'a long enough passphrase', 'phone' => '+14155552671', 'player_for' => 'me', 'player_name' => '', 'birth_date' => '1990-04-02', 'gender' => 'other']);

        self::assertStringContainsString('name="email" type="email" value=""', $body);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'pat@example.com'"));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
    }
}
