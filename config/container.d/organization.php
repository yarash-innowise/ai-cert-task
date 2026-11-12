<?php

declare(strict_types=1);

use App\Organization\Application\OrganizationRoster;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\OrganizationBrandings;
use App\Organization\Infrastructure\PdoOrganizationBrandings;
use App\Shared\Http\BrandingHolder;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\TwigFunction;
use App\Organization\Domain\CoachMemberships;
use App\Organization\Domain\OrganizationRepository;
use App\Organization\Infrastructure\CoachMembershipContextSource;
use App\Organization\Infrastructure\PdoCoachInvitationRepository;
use App\Organization\Infrastructure\PdoCoachMemberships;
use App\Organization\Infrastructure\PdoOrganizationRoster;
use App\Organization\Infrastructure\OwnedOrganizationContextSource;
use App\Organization\Infrastructure\PdoOrganizationRepository;

use function DI\add;
use function DI\autowire;
use function DI\decorate;

/** Organization module bindings. */
return [
    OrganizationRepository::class => autowire(PdoOrganizationRepository::class),
    OrganizationBrandings::class => autowire(PdoOrganizationBrandings::class),
    BrandingHolder::class => autowire(),
    // Twig `brand()`: the saved branding of the request's active organization (null without one).
    Environment::class => decorate(static function (Environment $twig, ContainerInterface $c): Environment {
        $twig->addFunction(new TwigFunction('brand', static function () use ($c) {
            $holder = $c->get(BrandingHolder::class);
            assert($holder instanceof BrandingHolder);

            return $holder->get();
        }));

        return $twig;
    }),
    CoachInvitationRepository::class => autowire(PdoCoachInvitationRepository::class),
    OrganizationRoster::class => autowire(PdoOrganizationRoster::class),
    CoachMemberships::class => autowire(PdoCoachMemberships::class),
    'context.sources' => add([OwnedOrganizationContextSource::class, CoachMembershipContextSource::class]),
];
