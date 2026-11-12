<?php

declare(strict_types=1);

namespace Tests\Http\TenantIsolation;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Policy\OrganizationPolicy;
use App\Identity\Http\RequireAuth;
use App\Identity\Http\RequireVerifiedEmail;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\TenantMismatch;
use App\Shared\Http\RequestContext;
use League\Route\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-only tenant routes that show the convention every real tenant route follows: look the
 * record up, ask the policy, answer 404 (never 403) when it belongs to another organization.
 * They are registered with {@see TenantRouteRegistry} exactly like a real tenant route would be.
 */
final class TenantFixtureRoutes
{
    public const CONTEXT = '/_fixture/context';
    public const ORGANIZATION = '/_fixture/organizations/{id}';

    public static function register(Router $router, ContainerInterface $c): void
    {
        $auth = $c->get(RequireAuth::class);
        $verified = $c->get(RequireVerifiedEmail::class);
        $organizations = $c->get(OrganizationRepository::class);
        $policy = new OrganizationPolicy();
        \assert($auth instanceof RequireAuth && $verified instanceof RequireVerifiedEmail && $organizations instanceof OrganizationRepository);
        $factory = new Psr17Factory();
        $text = static fn(string $body): ResponseInterface => $factory->createResponse(200)->withBody($factory->createStream($body));

        $router->get(self::CONTEXT, static function (ServerRequestInterface $request) use ($text): ResponseInterface {
            $context = RequestContext::from($request);

            return $text(sprintf('org=%s;profile=%s', $context->organizationId->value ?? 'none', $context->profileId ?? 'none'));
        })->middleware($auth)->middleware($verified);

        $resolve = /** @param array<string, string> $args */ static function (ServerRequestInterface $request, array $args, bool $manage) use ($organizations, $policy): string {
            $user = $request->getAttribute('current_user');
            $id = OrganizationId::tryFromString((string) ($args['id'] ?? ''));
            $organization = $id === null ? null : $organizations->findById($id->value);
            if (!$user instanceof CurrentUser) {
                throw new Forbidden();
            }
            $context = RequestContext::from($request);
            $allowed = $organization !== null && ($manage
                ? $policy->canManage($user, $context, OrganizationId::fromString($organization->id))
                : $policy->canAccess($user, $context, OrganizationId::fromString($organization->id)));
            if ($organization === null || !$allowed) {
                throw new TenantMismatch();
            }

            return $organization->businessName;
        };

        $router->get(self::ORGANIZATION, static fn(ServerRequestInterface $r, array $a): ResponseInterface => $text('organization:' . $resolve($r, $a, false)))
            ->middleware($auth)->middleware($verified);
        $router->post(self::ORGANIZATION, static fn(ServerRequestInterface $r, array $a): ResponseInterface => $text('managed:' . $resolve($r, $a, true)))
            ->middleware($auth)->middleware($verified);
    }
}
