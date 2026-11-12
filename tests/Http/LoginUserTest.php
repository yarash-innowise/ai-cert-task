<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Identity\Domain\PasswordHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class LoginUserTest extends AppHttpTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function roles(): iterable
    {
        yield 'super admin' => ['super_admin', '/admin'];
        yield 'trainer' => ['trainer', '/trainer'];
        yield 'coach' => ['coach', '/coach'];
        yield 'player/parent' => ['player', '/family'];
    }

    #[Group('AC-1')]
    #[DataProvider('roles')]
    public function testValidCredentialsSignInAndLandOnTheRoleDashboard(string $role, string $path): void
    {
        $this->createUser($role, 'ok@example.com');

        $response = $this->login('ok@example.com');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($path, $this->location($response));
        self::assertSame(1, $this->scalar('SELECT count(*) FROM sessions'));
        self::assertSame(200, $this->get($path)->getStatusCode());
    }

    #[Group('AC-1')]
    public function testEmailMatchingIsCaseInsensitive(): void
    {
        $this->createUser('player', 'mixed@example.com');

        self::assertSame(302, $this->login('  Mixed@Example.COM ')->getStatusCode());
    }

    #[Group('AC-2')]
    public function testWrongEmailAndWrongPasswordGiveTheIdenticalMessage(): void
    {
        $this->createUser('player', 'real@example.com');

        $wrongPassword = $this->login('real@example.com', 'definitely wrong pass');
        $this->cookies = [];
        $wrongEmail = $this->login('nobody@example.com', 'definitely wrong pass');
        $this->cookies = [];
        $malformed = $this->login('not-an-email', 'definitely wrong pass');

        foreach ([$wrongPassword, $wrongEmail, $malformed] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertStringContainsString('Email or password is incorrect.', $this->body($response));
        }
        $extract = fn($r) => preg_replace('/(value|name="_csrf")[^>]*>/', '>', $this->block($this->body($r)));
        self::assertSame($extract($wrongPassword), $extract($wrongEmail));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
    }

    private function block(string $html): string
    {
        return preg_match('/<div class="error-summary".*?<\/div>/s', $html, $m) === 1 ? $m[0] : '';
    }

    #[Group('AC-2')]
    public function testEmptyFieldsAreReportedPerField(): void
    {
        $response = $this->post('/login', ['email' => '', 'password' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter your email address.', $this->body($response));
        self::assertStringContainsString('Enter your password.', $this->body($response));
    }

    #[Group('AC-73')]
    public function testInactiveUserSeesTheDeactivatedMessage(): void
    {
        $this->createUser('player', 'gone@example.com', status: 'inactive');

        $response = $this->login('gone@example.com');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Account deactivated. Contact support.', $this->body($response));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
    }

    #[Group('AC-73')]
    public function testDeactivatedStatusIsNotRevealedWithoutTheCorrectPassword(): void
    {
        $this->createUser('player', 'gone@example.com', status: 'inactive');

        $response = $this->login('gone@example.com', 'wrong password here');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('deactivated', $this->body($response));
    }

    #[Group('AC-2')]
    public function testDeletedUserGetsTheGenericMessage(): void
    {
        $this->createUser('player', 'deleted_1@example.com', status: 'deleted');

        $response = $this->login('deleted_1@example.com');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Email or password is incorrect.', $this->body($response));
    }

    #[Group('AC-7')]
    public function testUnverifiedSelfRegisteredUserIsToldToVerify(): void
    {
        $this->createUser('player', 'new@example.com', verified: false);

        $response = $this->login('new@example.com');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Please verify your email address before signing in.', $this->body($response));
        self::assertStringContainsString('/verify-email/resend', $this->body($response));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
    }

    #[Group('AC-4')]
    public function testPasswordIsStoredAsArgon2idAndNeverAppearsInOutputOrLogs(): void
    {
        $spy = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . json_encode($context);
            }
        };
        assert($this->container instanceof \DI\Container);
        $this->container->set(LoggerInterface::class, $spy);

        $password = 'my secret passphrase 42';
        $this->createUser('player', 'hash@example.com', $password);
        $stored = (string) $this->scalar('SELECT password_hash FROM users WHERE email = :e', ['e' => 'hash@example.com']);

        $outputs = [$this->body($this->login('hash@example.com', 'bad password attempt'))];
        $this->cookies = [];
        $outputs[] = $this->body($this->login('hash@example.com', $password));
        $outputs[] = $this->body($this->get('/family'));

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertNotSame($password, $stored);
        foreach (array_merge($outputs, $spy->lines) as $text) {
            self::assertStringNotContainsString($password, $text);
            self::assertStringNotContainsString($stored, $text);
            self::assertStringNotContainsString('bad password attempt', $text);
        }
        foreach ($spy->lines as $line) {
            self::assertStringNotContainsString('hash@example.com', $line, 'no email in logs');
        }
    }

    #[Group('AC-4')]
    public function testHashIsUpgradedOnSuccessfulLogin(): void
    {
        $password = 'correct horse battery';
        $this->createUser('player', 'old@example.com', $password);
        $old = (string) $this->scalar('SELECT password_hash FROM users WHERE email = :e', ['e' => 'old@example.com']);

        $stronger = new PasswordHasher(['memory_cost' => 2048, 'time_cost' => 1, 'threads' => 1]);
        assert($this->container instanceof \DI\Container);
        $this->container->set(PasswordHasher::class, $stronger);

        self::assertSame(302, $this->login('old@example.com', $password)->getStatusCode());

        $new = (string) $this->scalar('SELECT password_hash FROM users WHERE email = :e', ['e' => 'old@example.com']);
        self::assertNotSame($old, $new);
        self::assertFalse($stronger->needsRehash($new));
        self::assertTrue($stronger->verify($password, $new));
    }
}
