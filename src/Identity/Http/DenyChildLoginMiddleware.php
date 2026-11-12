<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route guard for everything a child login must never do (AC-47): adding Trainers, payment
 * methods, buying tokens, completing a purchase, deleting the account, changing associations,
 * reading the parent's data. A direct address is answered 403 and nothing changes. The use cases
 * re-check through ChildCapabilities, so this guard is not the only line of defence.
 */
final class DenyChildLoginMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if ($user instanceof CurrentUser && $user->isChildLogin()) {
            throw new Forbidden();
        }

        return $handler->handle($request);
    }
}
