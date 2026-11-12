<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;
use Psr\Http\Message\ServerRequestInterface;

/** Reads the authenticated principal set by AuthenticationMiddleware. */
trait ActorAware
{
    private function actor(ServerRequestInterface $request): CurrentUser
    {
        $actor = $request->getAttribute('current_user');

        return $actor instanceof CurrentUser ? $actor : throw new Forbidden();
    }

    /** @return array<array-key, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }
}
