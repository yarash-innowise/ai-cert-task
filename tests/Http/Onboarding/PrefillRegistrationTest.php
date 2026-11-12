<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Integration\Application\RegistrationPrefillWriter;
use App\Shared\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Group;

final class PrefillRegistrationTest extends OnboardingHttpTestCase
{
    /** @return array<string, string> */
    private function camp(): array
    {
        return ['name' => 'Pat Parent', 'email' => 'Pat@Example.com', 'phone' => '+14155552671', 'player_for' => 'child', 'player_name' => 'Mia Parent', 'birth_date' => '2022-05-01', 'gender' => 'female'];
    }

    /**
     * @param array<string, string>|null $fields
     * @return array{string, string} organization id and token
     */
    private function prefill(?array $fields = null): array
    {
        [, $org] = $this->trainerWithOrganization();
        $writer = $this->container->get(RegistrationPrefillWriter::class);
        self::assertInstanceOf(RegistrationPrefillWriter::class, $writer);

        return [$org, $writer->write(OrganizationId::fromString($org), $fields ?? $this->camp())];
    }

    /** @return array<string, string> the form a person submits: only the password is new */
    private function submitted(): array
    {
        return ['name' => 'Pat Parent', 'email' => 'pat@example.com', 'password' => 'a long enough passphrase', 'phone' => '+14155552671', 'player_for' => 'child', 'player_name' => 'Mia Parent', 'birth_date' => '2022-05-01', 'gender' => 'female'];
    }

    #[Group('AC-85')]
    public function testAValidTokenPreFillsTheFormAndTheUrlCarriesNoPersonalData(): void
    {
        [, $token] = $this->prefill();

        $response = $this->get('/register/prefill/' . $token);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertStringContainsString('value="pat@example.com"', $body);
        self::assertStringContainsString('value="Pat Parent"', $body);
        self::assertStringContainsString('value="2022-05-01"', $body);
        self::assertSame(hash('sha256', $token), $this->scalar('SELECT token_hash FROM registration_prefills'));
    }

    #[Group('AC-85')]
    public function testCompletingTheFormCreatesTheAccountAutoAssignedToTheTrainerWithoutRetyping(): void
    {
        [$org, $token] = $this->prefill();

        $response = $this->post('/register/prefill/' . $token, $this->submitted());

        self::assertSame(200, $response->getStatusCode());
        $user = (string) $this->scalar('SELECT id FROM users WHERE email = :e', ['e' => 'pat@example.com']);
        self::assertNotSame('', $user);
        $profile = (string) $this->scalar("SELECT id FROM player_profiles WHERE owner_user_id = :o AND kind = 'child'", ['o' => $user]);
        self::assertSame($org, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $profile]));
        self::assertNotNull($this->scalar('SELECT consumed_at FROM registration_prefills'));
        self::assertSame('standard', $this->scalar('SELECT account_kind FROM users WHERE id = :u', ['u' => $user]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'player.registered_via_prefill'"));
    }

    #[Group('AC-85'), Group('AC-86')]
    public function testConfirmationAndVerificationEmailsAreQueued(): void
    {
        [, $token] = $this->prefill();

        $this->post('/register/prefill/' . $token, $this->submitted());

        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'registration-confirmation'"));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'email-verification'"));
    }

    #[Group('AC-85')]
    public function testATokenWorksOnlyOnce(): void
    {
        [$org, $token] = $this->prefill();
        $this->post('/register/prefill/' . $token, $this->submitted());

        $again = $this->get('/register/prefill/' . $token);
        $second = $this->post('/register/prefill/' . $token, [...$this->submitted(), 'email' => 'other@example.com']);

        self::assertStringNotContainsString('value="pat@example.com"', $this->body($again));
        self::assertSame(200, $second->getStatusCode());
        $other = (string) $this->scalar('SELECT id FROM users WHERE email = :e', ['e' => 'other@example.com']);
        $profile = (string) $this->scalar("SELECT id FROM player_profiles WHERE owner_user_id = :o", ['o' => $other]);
        self::assertSame(0, $this->activeAssociations($profile), 'the second use is an ordinary registration, not assigned to ' . $org);
    }

    #[Group('AC-85')]
    public function testAnOrdinaryRegistrationWithoutAValidTokenCreatesAnUnassignedAccount(): void
    {
        $token = str_repeat('A', 43);

        $form = $this->get('/register/prefill/' . $token);
        $response = $this->post('/register/prefill/' . $token, $this->submitted());

        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="email" type="email" value=""', preg_replace('/\s+/', ' ', $this->body($form)) ?? '');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'pat@example.com'"));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
    }

    #[Group('AC-85')]
    public function testATokenOfAnInactiveOrganizationIsNotUsable(): void
    {
        [$org, $token] = $this->prefill();
        $this->pdo->prepare("UPDATE organizations SET status = 'inactive' WHERE id = :o")->execute(['o' => $org]);

        $form = $this->body($this->get('/register/prefill/' . $token));

        self::assertStringNotContainsString('value="pat@example.com"', $form);
    }

    #[Group('AC-85')]
    public function testTheWriterKeepsOnlyKnownFieldsAndFourteenDays(): void
    {
        [, $token] = $this->prefill([...$this->camp(), 'ssn' => '123', 'password' => 'secret']);

        $row = $this->row('SELECT data, expires_at FROM registration_prefills');
        self::assertIsArray($row);
        $data = json_decode((string) $row['data'], true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('ssn', $data);
        self::assertArrayNotHasKey('password', $data);
        self::assertSame('pat@example.com', $data['email']);
        self::assertSame('2030-03-15 10:00:00+00', $row['expires_at']);
        self::assertNotSame('', $token);
    }
}
