<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Admin\Infrastructure\Console\SeedUsersCommand;
use App\Admin\Infrastructure\UserSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

#[Group('AC-88')]
final class UserSeederTest extends IntegrationTestCase
{
    public function testSeedingAddsUsersWithoutPasswordsAndIsRepeatable(): void
    {
        $seeder = new UserSeeder($this->pdo);

        self::assertSame(20, $seeder->seed(20));
        self::assertSame(5, $seeder->seed(25));
        self::assertSame(25, (int) $this->scalar("SELECT count(*) FROM users WHERE email LIKE 'seed-%@example.test'"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE email LIKE 'seed-%' AND password_hash IS NOT NULL"));
    }

    public function testTheCommandRefusesToRunInProduction(): void
    {
        $command = new SeedUsersCommand(new UserSeeder($this->pdo), 'production');

        self::assertSame(1, @$command->run(['5']));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE email LIKE 'seed-%'"));
    }

    public function testTheCommandSeedsTheRequestedNumberOutsideProduction(): void
    {
        $command = new SeedUsersCommand(new UserSeeder($this->pdo), 'testing');

        \ob_start();
        $code = $command->run(['7']);
        \ob_end_clean();

        self::assertSame(0, $code);
        self::assertSame(7, (int) $this->scalar("SELECT count(*) FROM users WHERE email LIKE 'seed-%'"));
    }
}
