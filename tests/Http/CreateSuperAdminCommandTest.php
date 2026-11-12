<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Admin\Infrastructure\Console\CreateSuperAdminCommand;
use PHPUnit\Framework\Attributes\Group;

final class CreateSuperAdminCommandTest extends AppHttpTestCase
{
    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function execute(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        \assert($in !== false && $out !== false && $err !== false);
        fwrite($in, $stdin);
        rewind($in);

        \assert($this->container instanceof \DI\Container);
        $command = $this->container->make(CreateSuperAdminCommand::class, ['stdin' => $in, 'stdout' => $out, 'stderr' => $err]);
        \assert($command instanceof CreateSuperAdminCommand);
        $code = $command->run($args);

        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    #[Group('AC-1')]
    public function testCreatedSuperAdminSignsInAndLandsOnTheAdminDashboard(): void
    {
        [$code, $out] = $this->execute(['--email=Ada@Example.com', '--name=Ada Admin', '--password-stdin'], "a long passphrase here\n");

        self::assertSame(0, $code);
        self::assertStringContainsString('Super Admin created', $out);
        self::assertSame('super_admin', $this->scalar("SELECT role FROM users WHERE email = 'ada@example.com'"));
        self::assertSame('active', $this->scalar("SELECT status FROM users WHERE email = 'ada@example.com'"));

        $login = $this->login('ada@example.com', 'a long passphrase here');
        self::assertSame('/admin', $this->location($login));
        self::assertSame(200, $this->get('/admin')->getStatusCode());
    }

    #[Group('AC-4')]
    public function testPasswordIsStoredHashedAndNeverPrinted(): void
    {
        [, $out, $err] = $this->execute(['--email=ada@example.com', '--name=Ada', '--password-stdin'], "a long passphrase here\n");

        self::assertStringNotContainsString('a long passphrase here', $out . $err);
        $hash = (string) $this->scalar("SELECT password_hash FROM users WHERE email = 'ada@example.com'");
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('a long passphrase here', (string) $this->scalar('SELECT string_agg(payload::text, \'\') FROM audit_log'));
    }

    #[Group('AC-87')]
    public function testCreationIsAudited(): void
    {
        $this->execute(['--email=ada@example.com', '--name=Ada', '--password-stdin'], "a long passphrase here\n");

        $id = $this->scalar("SELECT id FROM users WHERE email = 'ada@example.com'");
        self::assertSame(1, $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'superadmin.created' AND subject_id = :i", ['i' => $id]));
    }

    public function testMissingValuesArePromptedInOrderEmailNamePassword(): void
    {
        [$code, $out] = $this->execute([], "ada@example.com\nAda Admin\na long passphrase here\n");

        self::assertSame(0, $code);
        self::assertStringContainsString('Email: ', $out);
        self::assertStringContainsString('Password: ', $out);
        self::assertStringNotContainsString('a long passphrase here', $out, 'the secret is never echoed');
        self::assertSame('Ada Admin', $this->scalar("SELECT name FROM users WHERE email = 'ada@example.com'"));
    }

    #[Group('AC-4')]
    public function testWeakPasswordDuplicateEmailAndBadInputAreRejectedWithNothingCreated(): void
    {
        [$code, , $err] = $this->execute(['--email=ada@example.com', '--name=Ada', '--password-stdin'], "short\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('password: Password must be 10 to 128 characters long.', $err);

        [$code, , $err] = $this->execute(['--email=nope', '--name=Ada', '--password-stdin'], "a long passphrase here\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('email:', $err);

        [$code, , $err] = $this->execute(['--email=ada@example.com', '--name= ', '--password-stdin'], "a long passphrase here\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('name: Name is required.', $err);
        self::assertSame(0, $this->scalar('SELECT count(*) FROM users'));

        self::assertSame(0, $this->execute(['--email=ada@example.com', '--name=Ada', '--password-stdin'], "a long passphrase here\n")[0]);
        [$code, , $err] = $this->execute(['--email=ADA@example.com', '--name=Ada Two', '--password-stdin'], "another long passphrase\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('already registered', $err);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM users'));

        [$code] = $this->execute(['--password=secret-on-the-command-line']);
        self::assertSame(2, $code, 'passwords are never accepted as arguments');
    }

    #[Group('AC-12')]
    public function testNoWebRouteCreatesSuperAdminsOrSelfRegisteredTrainers(): void
    {
        $paths = ['/register', '/signup', '/register/trainer', '/trainer/register', '/admin/super-admins', '/super-admin', '/users', '/api/users'];
        foreach ($paths as $path) {
            self::assertSame(404, $this->post($path, ['role' => 'super_admin', 'email' => 'x@example.com', 'name' => 'X', 'password' => 'a long passphrase here'])->getStatusCode(), "POST {$path}");
            self::assertSame(404, $this->get($path)->getStatusCode(), "GET {$path}");
        }
        self::assertSame(0, $this->scalar('SELECT count(*) FROM users'));
    }

    public function testCommandIsRegisteredWithTheConsole(): void
    {
        /** @var list<class-string> $classes */
        $classes = require __DIR__ . '/../../config/commands.php';
        self::assertContains(CreateSuperAdminCommand::class, $classes);
    }
}
