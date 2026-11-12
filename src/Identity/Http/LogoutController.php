<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\SessionState;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /logout (CSRF protected): the server-side session row is deleted and the cookie cleared. */
final class LogoutController
{
    public function __construct(private readonly Redirect $redirect) {}

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $state = $request->getAttribute('session_state');
        if ($state instanceof SessionState) {
            $state->end();
        }

        return $this->redirect->to('/login?notice=signed_out');
    }
}
