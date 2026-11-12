<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class PasswordResetTest extends AppHttpTestCase
{
    /** @return list<array{to: string, context: array<string, mixed>, template: string}> */
    private function emails(string $template): array
    {
        $stmt = $this->pdo->prepare("SELECT topic, payload FROM outbox WHERE kind = 'email' AND topic = :t ORDER BY id");
        $stmt->execute(['t' => $template]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            /** @var array{to: string, context: array<string, mixed>} $payload */
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $out[] = ['to' => $payload['to'], 'context' => $payload['context'], 'template' => (string) $row['topic']];
        }

        return $out;
    }

    private function requestReset(string $email): void
    {
        $this->cookies = [];
        $response = $this->post('/forgot-password', ['email' => $email]);
        self::assertSame(200, $response->getStatusCode());
    }

    private function lastToken(): string
    {
        $emails = $this->emails('password-reset');
        $link = (string) (array_last($emails)['context']['link'] ?? '');
        self::assertMatchesRegularExpression('#^https://localhost:18443/reset-password/[A-Za-z0-9_-]{43}$#', $link);

        return basename($link);
    }

    private function choosePassword(string $token, string $password = 'a brand new passphrase', ?string $confirmation = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/reset-password/' . $token, ['password' => $password, 'password_confirmation' => $confirmation ?? $password]);
    }

    #[Group('AC-5')]
    #[Group('AC-86')]
    public function testResetEmailIsQueuedWithAWorkingOneTimeLink(): void
    {
        $this->createUser('player', 'who@example.com', name: 'Wendy Who');

        $this->requestReset('Who@Example.com');

        $emails = $this->emails('password-reset');
        self::assertCount(1, $emails);
        self::assertSame('who@example.com', $emails[0]['to']);

        // The queued message renders to a real email (subject, HTML and text twin), carrying the link.
        $twig = $this->container->get(\Twig\Environment::class);
        \assert($twig instanceof \Twig\Environment);
        $mail = (new EmailRenderer($twig))->render('password-reset', $emails[0]['to'], $emails[0]['context'], 'id-1');
        self::assertSame('Reset your password', $mail->subject);
        self::assertStringContainsString((string) $emails[0]['context']['link'], $mail->html);
        self::assertStringContainsString((string) $emails[0]['context']['link'], $mail->text);
        self::assertStringContainsString('1 hour', $mail->text);

        $page = $this->get('/reset-password/' . $this->lastToken());
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Choose a new password', $this->body($page));
    }

    #[Group('AC-5')]
    public function testOnlyTheHashOfTheTokenIsStored(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        self::assertSame(1, $this->scalar('SELECT count(*) FROM auth_tokens WHERE token_hash = :h', ['h' => hash('sha256', $token)]));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM auth_tokens WHERE token_hash = :h', ['h' => $token]));
    }

    #[Group('AC-5')]
    public function testLinkWorksOnceAndTheNewPasswordSignsIn(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        $done = $this->choosePassword($token);
        self::assertSame(302, $done->getStatusCode());
        self::assertSame('/login?notice=reset', $this->location($done));

        $this->cookies = [];
        self::assertSame(401, $this->login('who@example.com', 'correct horse battery')->getStatusCode(), 'old password is gone');
        $this->cookies = [];
        self::assertSame(302, $this->login('who@example.com', 'a brand new passphrase')->getStatusCode());

        // the same link again is refused with a clear message and a way to request a new one
        $this->cookies = [];
        $again = $this->choosePassword($token, 'yet another passphrase');
        self::assertSame(410, $again->getStatusCode());
        self::assertStringContainsString('expired or has already been used', $this->body($again));
        self::assertStringContainsString('href="/forgot-password"', $this->body($again));
        $this->cookies = [];
        self::assertSame(401, $this->login('who@example.com', 'yet another passphrase')->getStatusCode());
        self::assertSame(410, $this->get('/reset-password/' . $token)->getStatusCode());
    }

    #[Group('AC-5')]
    public function testLinkIsRefusedAfterOneHour(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        $this->clock->advance('59 minutes 59 seconds');
        self::assertSame(200, $this->get('/reset-password/' . $token)->getStatusCode(), 'still valid just under 1 hour');

        $this->clock->advance('2 seconds'); // 1 h + 1 s
        $page = $this->get('/reset-password/' . $token);
        self::assertSame(410, $page->getStatusCode());
        self::assertStringContainsString('Request a new link', $this->body($page));

        $submit = $this->choosePassword($token);
        self::assertSame(410, $submit->getStatusCode());
        $this->cookies = [];
        self::assertSame(302, $this->login('who@example.com')->getStatusCode(), 'password unchanged');
    }

    #[Group('AC-5')]
    public function testANewerLinkKillsTheOlderOne(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $older = $this->lastToken();
        $this->clock->advance('5 minutes');
        $this->requestReset('who@example.com');
        $newer = $this->lastToken();

        self::assertNotSame($older, $newer);
        self::assertSame(2, count($this->emails('password-reset')));
        self::assertSame(410, $this->choosePassword($older)->getStatusCode());
        self::assertSame(302, $this->choosePassword($newer)->getStatusCode());
    }

    #[Group('AC-5')]
    public function testUnknownEmailGetsTheSameNeutralAnswerAndNoMail(): void
    {
        $this->createUser('player', 'who@example.com');

        $known = $this->post('/forgot-password', ['email' => 'who@example.com']);
        $this->cookies = [];
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $this->cookies = [];
        $malformed = $this->post('/forgot-password', ['email' => 'not an email']);

        $strip = static fn(string $html): string => (string) preg_replace('/name="_csrf" value="[^"]*"|nonce="[^"]*"/', '', $html);
        self::assertSame(200, $unknown->getStatusCode());
        self::assertSame($strip($this->body($known)), $strip($this->body($unknown)));
        self::assertSame($strip($this->body($known)), $strip($this->body($malformed)));
        self::assertCount(1, $this->emails('password-reset'), 'only the real account got an email');
        self::assertSame(1, $this->scalar('SELECT count(*) FROM auth_tokens'));
    }

    #[Group('AC-5')]
    public function testInactiveAndDeletedAccountsGetNoResetEmail(): void
    {
        $this->createUser('player', 'off@example.com', status: 'inactive');
        $this->createUser('player', 'gone@example.com', status: 'deleted');

        $this->requestReset('off@example.com');
        $this->requestReset('gone@example.com');

        self::assertCount(0, $this->emails('password-reset'));
    }

    #[Group('AC-5')]
    #[Group('AC-8')]
    public function testCompletingAResetEndsEveryOtherSessionAndIsAudited(): void
    {
        $id = $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $stolen = $this->cookies;
        $this->cookies = [];
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        $this->choosePassword($token);

        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $id]));
        self::assertSame(302, $this->get('/family', [], $stolen)->getStatusCode(), 'old session no longer works');
        self::assertStringStartsWith('/login', $this->location($this->get('/family', [], $stolen)));
        self::assertSame(1, $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'user.password_changed' AND subject_id = :u", ['u' => $id]));
        self::assertStringNotContainsString('a brand new passphrase', (string) $this->scalar("SELECT payload::text FROM audit_log WHERE action = 'user.password_changed'"));
    }

    #[Group('AC-5')]
    public function testWeakOrMismatchedPasswordKeepsTheLinkUsable(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        $weak = $this->choosePassword($token, 'short');
        self::assertSame(422, $weak->getStatusCode());
        self::assertStringContainsString('Password must be 10 to 128 characters long.', $this->body($weak));
        $mismatch = $this->choosePassword($token, 'a brand new passphrase', 'something different');
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertStringContainsString('The two passwords do not match.', $this->body($mismatch));

        self::assertSame(302, $this->choosePassword($token)->getStatusCode(), 'the link was not consumed by the failed attempts');
    }

    #[Group('AC-5')]
    #[Group('AC-98')]
    public function testOnceTheWorkerSentTheResetEmailTheOutboxRowHoldsNoTokenLinkAddressOrName(): void
    {
        $mail = new \App\Shared\Infrastructure\Mail\InMemoryMailSender();
        \assert($this->container instanceof \DI\Container);
        $this->container->set(\App\Shared\Infrastructure\Mail\MailSender::class, $mail);
        $this->createUser('player', 'who@example.com', name: 'Wendy Who');
        $this->requestReset('who@example.com');
        $token = $this->lastToken();

        $worker = $this->container->get(\App\Shared\Infrastructure\Outbox\OutboxWorker::class);
        \assert($worker instanceof \App\Shared\Infrastructure\Outbox\OutboxWorker);
        $worker->runOnce();

        self::assertCount(1, $mail->sent);
        self::assertStringContainsString($token, $mail->sent[0]->text, 'the mail itself carried the link');
        $row = $this->row("SELECT status, topic, payload::text AS payload, last_error FROM outbox WHERE topic = 'password-reset'");
        self::assertIsArray($row);
        self::assertSame('done', $row['status']);
        self::assertSame('{}', $row['payload']);
        foreach ([$token, 'who@example.com', 'Wendy', 'reset-password'] as $leak) {
            self::assertStringNotContainsString($leak, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    #[Group('AC-5')]
    public function testUnknownTokenIsRefused(): void
    {
        self::assertSame(410, $this->get('/reset-password/' . str_repeat('a', 43))->getStatusCode());
        self::assertSame(410, $this->choosePassword(str_repeat('a', 43))->getStatusCode());
    }

    #[Group('AC-5')]
    public function testTokenPurgeRemovesOnlyLongExpiredTokens(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->requestReset('who@example.com');
        $this->clock->advance('3 days');
        $task = $this->container->get(\App\Identity\Infrastructure\PurgeAuthTokensTask::class);
        \assert($task instanceof \App\Identity\Infrastructure\PurgeAuthTokensTask);
        $task->run();
        self::assertSame(1, $this->scalar('SELECT count(*) FROM auth_tokens'), 'expired but recent: kept');

        $this->clock->advance('8 days');
        $task->run();
        self::assertSame(0, $this->scalar('SELECT count(*) FROM auth_tokens'));
    }
}
