<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Identity\Application\IssueEmailVerification;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class EmailVerificationTest extends AppHttpTestCase
{
    /** @return list<array{to: string, context: array<string, mixed>}> */
    private function emails(): array
    {
        $result = $this->pdo->query("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'email-verification' ORDER BY id");
        \assert($result !== false);
        $rows = $result->fetchAll();

        return array_values(array_map(static function (array $row): array {
            /** @var array{to: string, context: array<string, mixed>} $p */
            $p = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

            return $p;
        }, $rows));
    }

    private function lastLink(): string
    {
        $emails = $this->emails();

        return (string) (array_last($emails)['context']['link'] ?? '');
    }

    private function issueFor(string $userId): string
    {
        $issue = $this->container->get(IssueEmailVerification::class);
        \assert($issue instanceof IssueEmailVerification);
        $issue->forUser($userId);

        return basename($this->lastLink());
    }

    #[Group('AC-7')]
    #[Group('AC-86')]
    public function testVerificationEmailIsQueuedAndRendersWithItsLink(): void
    {
        $id = $this->createUser('player', 'new@example.com', verified: false, name: 'Nina New');
        $this->issueFor($id);

        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('new@example.com', $emails[0]['to']);
        self::assertMatchesRegularExpression('#^https://localhost:18443/verify-email/[A-Za-z0-9_-]{43}$#', (string) $emails[0]['context']['link']);

        $twig = $this->container->get(\Twig\Environment::class);
        \assert($twig instanceof \Twig\Environment);
        $mail = (new EmailRenderer($twig))->render('email-verification', $emails[0]['to'], $emails[0]['context'], 'id-1');
        self::assertSame('Confirm your email address', $mail->subject);
        self::assertStringContainsString((string) $emails[0]['context']['link'], $mail->html);
        self::assertStringContainsString('24 hours', $mail->text);
    }

    #[Group('AC-7')]
    public function testLinkConfirmsTheAddressOnceAndLoginThenWorks(): void
    {
        $id = $this->createUser('player', 'new@example.com', verified: false);
        self::assertSame(403, $this->login('new@example.com')->getStatusCode(), 'blocked before verification');
        $token = $this->issueFor($id);

        $page = $this->get('/verify-email/' . $token);
        self::assertSame(200, $page->getStatusCode());
        self::assertNull($this->scalar('SELECT email_verified_at FROM users WHERE id = :i', ['i' => $id]) ?: null, 'opening the link alone changes nothing');

        $done = $this->post('/verify-email/' . $token);
        self::assertSame('/login?notice=verified', $this->location($done));
        self::assertNotNull($this->scalar('SELECT email_verified_at FROM users WHERE id = :i', ['i' => $id]) ?: null);

        $this->cookies = [];
        self::assertSame(302, $this->login('new@example.com')->getStatusCode(), 'allowed after verification');

        $again = $this->post('/verify-email/' . $token);
        self::assertSame(410, $again->getStatusCode(), 'a used link is refused');
        self::assertStringContainsString('Send a new verification email', $this->body($again));
    }

    #[Group('AC-7')]
    public function testLinkIsRefusedAfter24HoursWithAResendOption(): void
    {
        $id = $this->createUser('player', 'new@example.com', verified: false);
        $token = $this->issueFor($id);

        $this->clock->advance('23 hours 59 minutes');
        self::assertSame(200, $this->get('/verify-email/' . $token)->getStatusCode());

        $this->clock->advance('2 minutes');
        $page = $this->get('/verify-email/' . $token);
        self::assertSame(410, $page->getStatusCode());
        self::assertStringContainsString('expire 24 hours after', $this->body($page));
        self::assertStringContainsString('href="/verify-email/resend"', $this->body($page));
        self::assertSame(410, $this->post('/verify-email/' . $token)->getStatusCode());
        self::assertNull($this->scalar('SELECT email_verified_at FROM users WHERE id = :i', ['i' => $id]) ?: null);
    }

    #[Group('AC-7')]
    public function testResendIssuesANewLinkAndKillsTheOldOne(): void
    {
        $id = $this->createUser('player', 'new@example.com', verified: false);
        $old = $this->issueFor($id);

        $response = $this->post('/verify-email/resend', ['email' => 'New@Example.com']);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->emails());

        $new = basename($this->lastLink());
        self::assertSame(410, $this->get('/verify-email/' . $old)->getStatusCode());
        self::assertSame(302, $this->post('/verify-email/' . $new)->getStatusCode());
    }

    #[Group('AC-7')]
    public function testResendIsNeutralForUnknownVerifiedAndInactiveAccounts(): void
    {
        $this->createUser('player', 'new@example.com', verified: false);
        $this->createUser('player', 'done@example.com');
        $this->createUser('player', 'off@example.com', status: 'inactive', verified: false);

        $answers = [];
        foreach (['new@example.com', 'nobody@example.com', 'done@example.com', 'off@example.com', 'garbage'] as $email) {
            $this->cookies = [];
            $answers[] = (string) preg_replace('/name="_csrf" value="[^"]*"|nonce="[^"]*"/', '', $this->body($this->post('/verify-email/resend', ['email' => $email])));
        }

        self::assertCount(1, array_unique($answers), 'identical answer for every address');
        self::assertCount(1, $this->emails(), 'only the real unverified account got an email');
    }

    #[Group('AC-7')]
    public function testUnknownTokenIsRefused(): void
    {
        self::assertSame(410, $this->get('/verify-email/' . str_repeat('b', 43))->getStatusCode());
    }

    #[Group('AC-7')]
    public function testVerifiedAccountsGetNoVerificationEmail(): void
    {
        $id = $this->createUser('player', 'done@example.com');
        $issue = $this->container->get(IssueEmailVerification::class);
        \assert($issue instanceof IssueEmailVerification);
        $issue->forUser($id);

        self::assertCount(0, $this->emails());
    }
}
