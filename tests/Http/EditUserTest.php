<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class EditUserTest extends AppHttpTestCase
{
    private string $targetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs('super_admin', 'admin@example.com', 'Ada Admin');
        $this->targetId = $this->createUser('coach', 'carl@example.com', name: 'Carl Coach');
    }

    private function versionOnForm(string $id): string
    {
        $html = $this->body($this->get("/admin/users/{$id}/edit"));
        self::assertSame(1, preg_match('#name="version" value="(\d+)"#', $html, $m), 'version field present');

        return $m[1] ?? '';
    }

    /** @param array<string, string> $fields */
    private function save(string $id, array $fields): \Psr\Http\Message\ResponseInterface
    {
        return $this->post("/admin/users/{$id}/edit", $fields);
    }

    /** @return array<string, mixed> */
    private function target(): array
    {
        return $this->row('SELECT name, email, phone, role, status, version FROM users WHERE id = :i', ['i' => $this->targetId]) ?? self::fail('missing');
    }

    #[Group('AC-80')]
    public function testSuperAdminEditsNamePhoneAndEmailOfAnyUser(): void
    {
        $version = $this->versionOnForm($this->targetId);

        $response = $this->save($this->targetId, ['name' => 'Carl Coach Jr', 'phone' => '+1 415 555 2671', 'email' => 'Carl.New@Example.com', 'version' => $version]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=user_updated', $this->location($response));
        $row = $this->target();
        self::assertSame('Carl Coach Jr', $row['name']);
        self::assertSame('+14155552671', $row['phone']);
        self::assertSame('carl.new@example.com', $row['email']);
        self::assertSame((int) $version + 1, $row['version']);
        $audit = $this->scalar("SELECT payload FROM audit_log WHERE action = 'user.updated' AND subject_id = :i", ['i' => $this->targetId]);
        self::assertIsString($audit);
        self::assertSame('name,phone,email', json_decode($audit, true, 512, JSON_THROW_ON_ERROR)['changed_fields']);
        self::assertStringNotContainsString('carl.new', $audit);
    }

    #[Group('AC-80')]
    public function testTheFormShowsCurrentValuesAndReadOnlyRoleStatusAndCreatedDate(): void
    {
        $html = $this->body($this->get("/admin/users/{$this->targetId}/edit"));

        self::assertStringContainsString('value="Carl Coach"', $html);
        self::assertStringContainsString('value="carl@example.com"', $html);
        self::assertStringContainsString('<dd>Coach</dd>', $html);
        self::assertStringNotContainsString('name="role"', $html);
        self::assertStringNotContainsString('name="status"', $html);
    }

    #[Group('AC-80')]
    public function testTheRoleCannotBeChangedByPostingIt(): void
    {
        $version = $this->versionOnForm($this->targetId);

        $this->save($this->targetId, ['name' => 'Carl Coach', 'phone' => '', 'email' => 'carl@example.com', 'version' => $version, 'role' => 'super_admin', 'status' => 'deleted']);

        $row = $this->target();
        self::assertSame('coach', $row['role']);
        self::assertSame('active', $row['status']);
    }

    #[Group('AC-80')]
    public function testTwoAdminsEditingTheSameUserTheLaterSaveIsRefusedAndNothingIsOverwritten(): void
    {
        $versionA = $this->versionOnForm($this->targetId);
        $versionB = $this->versionOnForm($this->targetId);
        self::assertSame($versionA, $versionB);

        $first = $this->save($this->targetId, ['name' => 'Name From Admin A', 'phone' => '', 'email' => 'carl@example.com', 'version' => $versionA]);
        self::assertSame(302, $first->getStatusCode());

        $second = $this->save($this->targetId, ['name' => 'Name From Admin B', 'phone' => '', 'email' => 'carl@example.com', 'version' => $versionB]);

        self::assertSame(409, $second->getStatusCode());
        $body = $this->body($second);
        self::assertStringContainsString('record changed', $body);
        self::assertStringContainsString('Reload', $body);
        self::assertStringContainsString('value="Name From Admin A"', $body, 'the current values are shown');
        self::assertSame('Name From Admin A', $this->target()['name']);
        self::assertSame((int) $versionA + 1, $this->target()['version']);
        // and after reloading the second admin can save
        $reloaded = $this->versionOnForm($this->targetId);
        self::assertSame(302, $this->save($this->targetId, ['name' => 'Name From Admin B', 'phone' => '', 'email' => 'carl@example.com', 'version' => $reloaded])->getStatusCode());
        self::assertSame('Name From Admin B', $this->target()['name']);
    }

    #[Group('AC-80')]
    public function testAnInvalidPhoneIsRefusedWithAMessageForThePhoneFieldAndNothingChanges(): void
    {
        $version = $this->versionOnForm($this->targetId);

        $response = $this->save($this->targetId, ['name' => 'Changed', 'phone' => '12', 'email' => 'carl@example.com', 'version' => $version]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-phone"', $this->body($response));
        self::assertSame('Carl Coach', $this->target()['name']);
        self::assertSame((int) $version, $this->target()['version']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidFields(): iterable
    {
        yield 'empty name' => ['name', '  ', 'Name is required.'];
        yield 'empty email' => ['email', '', 'Email is required.'];
        yield 'bad email' => ['email', 'not-an-email', 'Enter a valid email address.'];
    }

    #[Group('AC-80')]
    #[DataProvider('invalidFields')]
    public function testRequiredFieldsAreEnforcedAndNameTheField(string $field, string $value, string $message): void
    {
        $fields = ['name' => 'Carl Coach', 'phone' => '', 'email' => 'carl@example.com', 'version' => $this->versionOnForm($this->targetId)];
        $fields[$field] = $value;

        $response = $this->save($this->targetId, $fields);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString($message, $this->body($response));
        self::assertStringContainsString('href="#field-' . $field . '"', $this->body($response));
    }

    #[Group('AC-6')]
    #[Group('AC-80')]
    public function testChangingTheEmailToAnotherAccountsEmailInAnyCaseIsRefused(): void
    {
        $this->createUser('player', 'taken@example.com');
        $version = $this->versionOnForm($this->targetId);

        $response = $this->save($this->targetId, ['name' => 'Carl Coach', 'phone' => '', 'email' => 'TAKEN@example.com', 'version' => $version]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This email is already registered.', $this->body($response));
        self::assertSame('carl@example.com', $this->target()['email']);
        self::assertSame((int) $version, $this->target()['version']);
    }

    #[Group('AC-80')]
    public function testAMissingOrJunkVersionIsRefused(): void
    {
        $response = $this->save($this->targetId, ['name' => 'Carl Coach', 'phone' => '', 'email' => 'carl@example.com', 'version' => 'abc']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('out of date', $this->body($response));
    }

    #[Group('AC-80')]
    public function testAnUnknownOrMalformedUserIdIsNotFound(): void
    {
        self::assertSame(404, $this->get('/admin/users/00000000-0000-7000-8000-000000000000/edit')->getStatusCode());
        self::assertSame(404, $this->get('/admin/users/not-a-uuid/edit')->getStatusCode());
    }

    #[Group('AC-80')]
    public function testADeletedUserCannotBeEdited(): void
    {
        $deleted = $this->createUser('player', 'gone@example.com', status: 'deleted');
        $version = $this->versionOnForm($deleted);

        $response = $this->save($deleted, ['name' => 'Back', 'phone' => '', 'email' => 'gone@example.com', 'version' => $version]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('Test User', $this->scalar('SELECT name FROM users WHERE id = :i', ['i' => $deleted]));
    }

    #[Group('AC-69')]
    #[Group('AC-9')]
    #[DataProvider('nonAdminRoles')]
    public function testOnlyASuperAdminCanChangeEmailOrAnyFieldOfAUser(string $role): void
    {
        $this->cookies = [];
        $selfId = $this->signInAs($role, 'self@example.com', 'Self User');

        // not for another user, and not for their own record either: email change is a Super Admin action (Q-01.11)
        foreach ([$this->targetId, $selfId] as $id) {
            self::assertSame(403, $this->get("/admin/users/{$id}/edit")->getStatusCode());
            $response = $this->save($id, ['name' => 'Hacked', 'phone' => '', 'email' => 'hacked@example.com', 'role' => 'super_admin', 'version' => '1']);
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertSame('self@example.com', $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $selfId]));
        self::assertSame($role, $this->scalar('SELECT role FROM users WHERE id = :i', ['i' => $selfId]));
        self::assertSame('carl@example.com', $this->target()['email']);
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    /** @return array{id: string, token: string} a Trainer who has not yet chosen a password, with the first setup link */
    private function pendingTrainer(): array
    {
        $this->post('/admin/users/new', [
            'business_name' => 'Ace Tennis Academy',
            'trainer_name' => 'Tina Trainer',
            'email' => 'tina@example.com',
            'phone' => '+1 415 555 2671',
        ]);
        $id = $this->scalar("SELECT id FROM users WHERE email = 'tina@example.com'");
        self::assertIsString($id);

        return ['id' => $id, 'token' => $this->latestSetupToken()];
    }

    private function latestSetupToken(): string
    {
        $payload = $this->scalar("SELECT payload FROM outbox WHERE topic = 'trainer-setup' ORDER BY id DESC LIMIT 1");
        self::assertIsString($payload);
        /** @var array{context: array{link: string}} $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return basename($data['context']['link']);
    }

    #[Group('AC-14')]
    public function testChangingATrainersEmailRevokesTheOldSetupLinkAndSendsANewOneToTheNewAddress(): void
    {
        $trainer = $this->pendingTrainer();
        $adminCookies = $this->cookies;
        $version = $this->versionOnForm($trainer['id']);

        $response = $this->save($trainer['id'], ['name' => 'Tina Trainer', 'phone' => '+14155552671', 'email' => 'tina.new@example.com', 'version' => $version]);
        self::assertSame(302, $response->getStatusCode());

        $newToken = $this->latestSetupToken();
        self::assertNotSame($trainer['token'], $newToken);
        $recipient = $this->scalar("SELECT payload->>'to' FROM outbox WHERE topic = 'trainer-setup' ORDER BY id DESC LIMIT 1");
        self::assertSame('tina.new@example.com', $recipient);

        $this->cookies = [];
        self::assertSame(410, $this->get('/setup/' . $trainer['token'])->getStatusCode(), 'the old link is refused');
        self::assertSame(200, $this->get('/setup/' . $newToken)->getStatusCode(), 'the new link works');
        $this->cookies = $adminCookies;
    }

    #[Group('AC-5')]
    public function testChangingTheEmailRevokesLiveResetLinksAndEndsTheUsersSessions(): void
    {
        $now = $this->clock->now();
        $tokens = $this->container->get(\App\Identity\Domain\AuthTokenRepository::class);
        \assert($tokens instanceof \App\Identity\Domain\AuthTokenRepository);
        $reset = $tokens->issue($this->targetId, \App\Identity\Domain\AuthTokenPurpose::PasswordReset, $now);
        $this->pdo->prepare("INSERT INTO sessions (token_hash, user_id, csrf_token) VALUES ('h1', :u, 'c')")->execute(['u' => $this->targetId]);
        $version = $this->versionOnForm($this->targetId);

        self::assertSame(302, $this->save($this->targetId, ['name' => 'Carl Coach', 'phone' => '', 'email' => 'carl.moved@example.com', 'version' => $version])->getStatusCode());

        self::assertNull($tokens->peek($reset, \App\Identity\Domain\AuthTokenPurpose::PasswordReset, $now), 'old reset link is dead');
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->targetId]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'trainer-setup'"), 'a coach who already has a password gets no setup link');
        $audit = $this->scalar("SELECT payload FROM audit_log WHERE action = 'user.email_changed' AND subject_id = :i", ['i' => $this->targetId]);
        self::assertIsString($audit);
        self::assertStringNotContainsString('carl', $audit, 'IDs only: no address in the audit row');
    }

    public function testEditingWithoutChangingTheEmailKeepsLinksAndSessions(): void
    {
        $now = $this->clock->now();
        $tokens = $this->container->get(\App\Identity\Domain\AuthTokenRepository::class);
        \assert($tokens instanceof \App\Identity\Domain\AuthTokenRepository);
        $reset = $tokens->issue($this->targetId, \App\Identity\Domain\AuthTokenPurpose::PasswordReset, $now);
        $version = $this->versionOnForm($this->targetId);

        $this->save($this->targetId, ['name' => 'Carl Renamed', 'phone' => '', 'email' => 'carl@example.com', 'version' => $version]);

        self::assertNotNull($tokens->peek($reset, \App\Identity\Domain\AuthTokenPurpose::PasswordReset, $now));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'user.email_changed'"));
    }
}
