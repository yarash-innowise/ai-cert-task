<?php

declare(strict_types=1);

use App\Admin\Application\ImpersonationHistoryQuery;
use App\Admin\Application\UserDirectoryQuery;
use App\Admin\Domain\ImpersonationRepository;
use App\Admin\Domain\UserLifecycleStore;
use App\Admin\Http\ImpersonationHolder;
use App\Admin\Infrastructure\PdoImpersonationHistoryQuery;
use App\Admin\Infrastructure\PdoImpersonationRepository;
use App\Admin\Infrastructure\PdoUserLifecycleStore;
use App\Admin\Infrastructure\SweepImpersonationsTask;
use App\Admin\Infrastructure\Console\SeedUsersCommand;
use App\Admin\Infrastructure\PdoUserDirectoryQuery;
use App\Admin\Infrastructure\UserSeeder;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\TwigFunction;

use function DI\add;
use function DI\autowire;
use function DI\decorate;
use function DI\factory;

/** Admin module bindings. */
return [
    UserDirectoryQuery::class => autowire(PdoUserDirectoryQuery::class),
    UserLifecycleStore::class => autowire(PdoUserLifecycleStore::class),
    ImpersonationRepository::class => autowire(PdoImpersonationRepository::class),
    ImpersonationHistoryQuery::class => autowire(PdoImpersonationHistoryQuery::class),
    ImpersonationHolder::class => autowire(),
    'scheduler.tasks' => add([SweepImpersonationsTask::class]),
    // The layout shows the "Viewing as" banner while the request is an impersonation (`impersonation()` is null otherwise).
    Environment::class => decorate(static function (Environment $twig, ContainerInterface $c): Environment {
        $holder = $c->get(ImpersonationHolder::class);
        assert($holder instanceof ImpersonationHolder);
        $twig->addFunction(new TwigFunction('impersonation', static fn() => $holder->get()));

        return $twig;
    }),
    SeedUsersCommand::class => factory(static fn(ContainerInterface $c): SeedUsersCommand => new SeedUsersCommand(
        $c->get(UserSeeder::class),
        (string) ($c->get('settings')['app']['env'] ?? 'production'),
    )),
];
