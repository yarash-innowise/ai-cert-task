<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

final class TrainerSetupTest extends AppHttpTestCase
{
    private function createTrainerAndGetToken(): string
    {
        $this->signInAs('super_admin');
        $this->post('/admin/users/new', [
            'business_name' => 'Ace Tennis Academy',
            'trainer_name' => 'Tina Trainer',
            'email' => 'tina@example.com',
            'phone' => '+1 415 555 2671',
        ]);
        $payload = $this->scalar("SELECT payload FROM outbox WHERE topic = 'trainer-setup' ORDER BY id DESC LIMIT 1");
        self::assertIsString($payload);
        /** @var array{context: array{link: string}} $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->cookies = [];

        return basename($data['context']['link']);
    }

    #[Group('AC-98')]
    public function testOnceTheWorkerSentTheSetupEmailTheOutboxRowHoldsNoTokenLinkAddressOrName(): void
    {
        $mail = new \App\Shared\Infrastructure\Mail\InMemoryMailSender();
        \assert($this->container instanceof \DI\Container);
        $this->container->set(\App\Shared\Infrastructure\Mail\MailSender::class, $mail);
        $token = $this->createTrainerAndGetToken();

        $worker = $this->container->get(\App\Shared\Infrastructure\Outbox\OutboxWorker::class);
        \assert($worker instanceof \App\Shared\Infrastructure\Outbox\OutboxWorker);
        $worker->runOnce();

        self::assertNotSame([], $mail->sent);
        $row = $this->row("SELECT status, payload::text AS payload FROM outbox WHERE topic = 'trainer-setup'");
        self::assertIsArray($row);
        self::assertSame('done', $row['status']);
        self::assertSame('{}', $row['payload']);
        foreach ([$token, 'tina@example.com', 'Tina', 'Ace Tennis', '/setup/'] as $leak) {
            self::assertStringNotContainsString($leak, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    #[Group('AC-14')]
    #[Group('AC-16')]
    public function testTheTrainerSetsAPasswordFromTheLinkAndLandsOnTheTrainerDashboard(): void
    {
        $token = $this->createTrainerAndGetToken();

        self::assertSame(200, $this->get('/setup/' . $token)->getStatusCode());
        $response = $this->post('/setup/' . $token, ['password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/trainer', $this->location($response));
        $dashboard = $this->get('/trainer');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<h1>Trainer dashboard</h1>', $this->body($dashboard));
        $row = $this->row("SELECT status, must_set_password, email_verified_at FROM users WHERE email = 'tina@example.com'");
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertFalse($row['must_set_password']);
        self::assertNotNull($row['email_verified_at']);

        // and the Trainer can sign in again later with the chosen password
        $this->cookies = [];
        self::assertSame('/trainer', $this->location($this->login('tina@example.com', 'a brand new passphrase')));
    }

    #[Group('AC-14')]
    public function testTheLinkWorksOnlyOnce(): void
    {
        $token = $this->createTrainerAndGetToken();
        $this->post('/setup/' . $token, ['password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase']);
        $this->cookies = [];

        self::assertSame(410, $this->get('/setup/' . $token)->getStatusCode());
        self::assertSame(410, $this->post('/setup/' . $token, ['password' => 'another good passphrase', 'password_confirmation' => 'another good passphrase'])->getStatusCode());
    }

    #[Group('AC-14')]
    public function testTheLinkExpiresAfterSevenDays(): void
    {
        $token = $this->createTrainerAndGetToken();
        $this->clock->advance('+7 days +1 second');

        self::assertSame(410, $this->get('/setup/' . $token)->getStatusCode());
    }

    #[Group('AC-14')]
    public function testAWeakOrMismatchedPasswordIsRefusedAndTheLinkStaysUsable(): void
    {
        $token = $this->createTrainerAndGetToken();

        $weak = $this->post('/setup/' . $token, ['password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $weak->getStatusCode());
        self::assertStringContainsString('href="#field-password"', $this->body($weak));
        $mismatch = $this->post('/setup/' . $token, ['password' => 'a brand new passphrase', 'password_confirmation' => 'different one entirely']);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertStringContainsString('href="#field-password_confirmation"', $this->body($mismatch));
        self::assertSame(200, $this->get('/setup/' . $token)->getStatusCode());
    }

    #[Group('AC-15')]
    public function testAFlaggedTrainerCannotReachAnyPageBeforeSettingAPassword(): void
    {
        $id = $this->createUser('trainer', 'flagged@example.com', mustSetPassword: true, verified: false);
        $this->startSession($id);

        foreach (['/trainer', '/', '/family', '/admin/users/new'] as $path) {
            $response = $this->get($path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertSame('/password/set', $this->location($response), $path);
        }
        self::assertSame(200, $this->get('/password/set')->getStatusCode());

        $done = $this->post('/password/set', ['password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase']);
        self::assertSame('/trainer', $this->location($done));
        self::assertSame(200, $this->get('/trainer')->getStatusCode());
        self::assertFalse($this->scalar('SELECT must_set_password FROM users WHERE id = :i', ['i' => $id]));
    }

    #[Group('AC-15')]
    public function testThePasswordSetPageIsNotForUsersWhoAlreadyHaveAPassword(): void
    {
        $this->signInAs('trainer');

        $response = $this->get('/password/set');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $this->location($response));
    }
}
