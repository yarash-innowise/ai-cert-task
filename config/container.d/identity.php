<?php

declare(strict_types=1);

use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\CoachProfileRepository;
use App\Identity\Domain\UserProfileRepository;
use App\Identity\Infrastructure\PdoCoachProfileRepository;
use App\Identity\Infrastructure\PdoUserProfileRepository;
use App\Identity\Domain\LoginThrottle;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\SessionRepository;
use App\Identity\Domain\UserRepository;
use App\Identity\Http\CsrfTokenHolder;
use App\Identity\Http\CurrentUserHolder;
use App\Identity\Infrastructure\PdoAuthTokenRepository;
use App\Identity\Infrastructure\PdoLoginThrottle;
use App\Identity\Infrastructure\PdoSessionRepository;
use App\Identity\Infrastructure\PdoUserRepository;
use App\Identity\Infrastructure\PurgeAuthTokensTask;
use App\Identity\Infrastructure\PurgeLoginAttemptsTask;
use App\Identity\Infrastructure\PurgeSessionsTask;
use App\Shared\Http\AppUrl;
use App\Shared\Http\ClientIp;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\TwigFunction;

use function DI\add;
use function DI\autowire;
use function DI\decorate;
use function DI\factory;

/** Identity module bindings (config/container.d is loaded after config/container.php). */
return [
    SessionRepository::class => autowire(PdoSessionRepository::class),
    UserRepository::class => autowire(PdoUserRepository::class),
    UserProfileRepository::class => autowire(PdoUserProfileRepository::class),
    CoachProfileRepository::class => autowire(PdoCoachProfileRepository::class),
    LoginThrottle::class => autowire(PdoLoginThrottle::class),
    AuthTokenRepository::class => autowire(PdoAuthTokenRepository::class),
    PasswordHasher::class => factory(static fn(): PasswordHasher => new PasswordHasher()),
    CsrfTokenHolder::class => autowire(),
    CurrentUserHolder::class => autowire(),
    ClientIp::class => factory(static fn(ContainerInterface $c): ClientIp => new ClientIp(
        array_values(array_map('strval', (array) ($c->get('settings')['trusted_proxies'] ?? []))),
    )),
    AppUrl::class => factory(static fn(ContainerInterface $c): AppUrl => new AppUrl(
        (string) ($c->get('settings')['app']['url'] ?? 'https://localhost:18443'),
    )),
    'scheduler.tasks' => add([
        PurgeSessionsTask::class,
        PurgeLoginAttemptsTask::class,
        PurgeAuthTokensTask::class,
    ]),
    Environment::class => decorate(static function (Environment $twig, ContainerInterface $c): Environment {
        $csrf = $c->get(CsrfTokenHolder::class);
        $current = $c->get(CurrentUserHolder::class);
        assert($csrf instanceof CsrfTokenHolder && $current instanceof CurrentUserHolder);

        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn(): string => '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrf->value(), ENT_QUOTES) . '">',
            ['is_safe' => ['html']],
        ));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => $csrf->value()));
        $twig->addFunction(new TwigFunction('current_user', static fn() => $current->get()));

        return $twig;
    }),
];
