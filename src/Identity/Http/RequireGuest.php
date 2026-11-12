<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Signed-in users are sent to their own dashboard instead of the login/registration pages. */
final class RequireGuest implements MiddlewareInterface
{
    public function __construct(private readonly Redirect $redirect) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if ($user instanceof CurrentUser) {
            return $this->redirect->to($user->role->dashboardPath());
        }

        return $handler->handle($request);
    }
}
