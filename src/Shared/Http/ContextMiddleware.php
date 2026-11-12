<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextOptions;
use App\Shared\Domain\OrganizationId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the RequestContext from the session's `active_context`. The remembered choice is
 * validated against the user's valid contexts on EVERY request (membership can end at any time);
 * if it is no longer valid, or none was chosen, the first valid context is used. A user without
 * any valid context gets an empty context and the tenant routes answer 404.
 */
final class ContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContextOptions $options,
        private readonly RequestContextHolder $holder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = RequestContext::none();
        $user = $request->getAttribute('current_user');

        if ($user instanceof CurrentUser) {
            $valid = $this->options->for($user->id, $user->role->value);
            $chosen = $this->chosen($request);
            $selected = null;
            foreach ($valid as $option) {
                if ($chosen !== null && $option->equals($chosen)) {
                    $selected = $option;
                    break;
                }
            }
            $selected ??= $valid[0] ?? null;
            if ($selected !== null) {
                $context = new RequestContext($selected->organizationId, $selected->profileId);
            }
        }

        $this->holder->set($context);

        return $handler->handle($request->withAttribute(RequestContext::ATTRIBUTE, $context));
    }

    private function chosen(ServerRequestInterface $request): ?ContextOption
    {
        $state = $request->getAttribute('session_state');
        $stored = $state instanceof SessionState ? $state->session()?->activeContext : null;
        if ($stored === null || !is_string($stored['organization_id'] ?? null)) {
            return null;
        }
        $organization = OrganizationId::tryFromString($stored['organization_id']);
        if ($organization === null) {
            return null;
        }
        $profile = $stored['profile_id'] ?? null;

        return new ContextOption($organization, is_string($profile) ? $profile : null);
    }
}
