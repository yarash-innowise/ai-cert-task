<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Organization\Domain\OrganizationBrandings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the logo and color of the request's ACTIVE organization for the layout (design C5, AC-83). It
 * reads the organization from the validated RequestContext only, so a user receives the branding of
 * the Trainer they are acting under and never anyone else's; without an organization context
 * (a Super Admin, an anonymous visitor) the platform default applies.
 */
final class BrandingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OrganizationBrandings $brandings,
        private readonly BrandingHolder $holder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $organization = RequestContext::from($request)->organizationId;
        $this->holder->set($organization === null ? null : $this->brandings->find($organization));

        return $handler->handle($request);
    }
}
