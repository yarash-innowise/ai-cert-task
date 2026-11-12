<?php

declare(strict_types=1);

namespace App\Shared\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $args arguments after the command name
     * @return int process exit code
     */
    public function run(array $args): int;
}
