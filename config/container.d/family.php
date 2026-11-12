<?php

declare(strict_types=1);

use App\Family\Application\CancelUpcomingRegistrationsJob;
use App\Family\Application\RemoveChildFromTrainer;
use App\Family\Application\TrainerRoster;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Family\Infrastructure\PdoPlayerProfileRepository;
use App\Family\Infrastructure\PdoTrainerAssociations;
use App\Family\Infrastructure\PdoTrainerRoster;
use App\Family\Application\ListContexts;
use App\Family\Infrastructure\FamilyContextSource;
use App\Identity\Http\CurrentUserHolder;
use App\Shared\Http\RequestContextHolder;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\TwigFunction;

use function DI\add;
use function DI\autowire;
use function DI\decorate;

/** Family module bindings. */
return [
    PlayerProfileRepository::class => autowire(PdoPlayerProfileRepository::class),
    TrainerAssociations::class => autowire(PdoTrainerAssociations::class),
    TrainerRoster::class => autowire(PdoTrainerRoster::class),
    'context.sources' => add([FamilyContextSource::class]),
    // Twig `context_menu()`: the selector's content for the current request (read lazily, once per page).
    Environment::class => decorate(static function (Environment $twig, ContainerInterface $c): Environment {
        $twig->addFunction(new TwigFunction('context_menu', static function () use ($c) {
            $user = $c->get(CurrentUserHolder::class);
            $context = $c->get(RequestContextHolder::class);
            $list = $c->get(ListContexts::class);
            assert($user instanceof CurrentUserHolder && $context instanceof RequestContextHolder && $list instanceof ListContexts);

            return $list->execute($user->get(), $context->get());
        }));

        return $twig;
    }),
    'job.handlers' => add([[RemoveChildFromTrainer::CANCEL_TOPIC, CancelUpcomingRegistrationsJob::class]]),
];
