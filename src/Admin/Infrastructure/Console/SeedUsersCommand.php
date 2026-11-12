<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Console;

use App\Admin\Infrastructure\UserSeeder;
use App\Shared\Console\Command;

/** `dev:seed-users 10000`: sample users for pagination and timing checks. Refuses to run in production. */
final class SeedUsersCommand implements Command
{
    public function __construct(
        private readonly UserSeeder $seeder,
        private readonly string $environment,
    ) {}

    public function name(): string
    {
        return 'dev:seed-users';
    }

    public function description(): string
    {
        return 'Add N sample users (default 10000) for pagination and timing checks; not for production';
    }

    public function run(array $args): int
    {
        if ($this->environment === 'production') {
            fwrite(STDERR, "Refusing to seed sample users in production.\n");

            return 1;
        }
        $count = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 10000;
        if ($count < 1 || $count > 1000000) {
            fwrite(STDERR, "Count must be between 1 and 1000000.\n");

            return 2;
        }

        fwrite(STDOUT, sprintf("Added %d sample users.\n", $this->seeder->seed($count)));

        return 0;
    }
}
