<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sends a signed-in user with an unverified email to the resend page. */
final class RequireVerifiedEmail implements MiddlewareInterface
{
    public function __construct(private readonly Redirect $redirect) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if ($user instanceof CurrentUser && !$user->emailVerified) {
            return $this->redirect->to('/verify-email/resend');
        }

        return $handler->handle($request);
    }
}
