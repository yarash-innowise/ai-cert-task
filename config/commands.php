<?php

declare(strict_types=1);

use App\Admin\Infrastructure\Console\CreateSuperAdminCommand;
use App\Admin\Infrastructure\Console\SeedUsersCommand;
use App\Shared\Infrastructure\Console\WorkerCommand;

/**
 * CLI command classes registered with bin/console. Later tasks append here.
 *
 * @return list<class-string<App\Shared\Console\Command>>
 */
return [
    WorkerCommand::class,
    CreateSuperAdminCommand::class,
    SeedUsersCommand::class,
];
