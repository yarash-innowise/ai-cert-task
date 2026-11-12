<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\Exception\Forbidden;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Server-side role gate for a route group (AC-9); put it after RequireAuth. */
final class RequireRole implements MiddlewareInterface
{
    /** @var list<Role> */
    private readonly array $roles;

    public function __construct(Role ...$roles)
    {
        $this->roles = array_values($roles);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser || !in_array($user->role, $this->roles, true)) {
            throw new Forbidden();
        }

        return $handler->handle($request);
    }
}
