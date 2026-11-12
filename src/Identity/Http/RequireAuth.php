<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\ReturnTo;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Anonymous visitors go to /login. A GET remembers where to come back to (same-origin relative
 * path only); a POST is never replayed: the body is discarded and, at most, the page of the form
 * (from a same-origin Referer) is offered after sign-in (edge: session timeout while filling a form).
 */
final class RequireAuth implements MiddlewareInterface
{
    public function __construct(private readonly Redirect $redirect) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('current_user') instanceof CurrentUser) {
            return $handler->handle($request);
        }

        $query = [];
        if ($request->getAttribute('session_ended_because') === 'deactivated') {
            $query['notice'] = 'deactivated';
        } elseif ($request->getAttribute('session_expired') === true) {
            $query['notice'] = 'expired';
        }

        $returnTo = strtoupper($request->getMethod()) === 'GET'
            ? $this->pathOf($request)
            : $this->refererPath($request);
        if ($returnTo !== null) {
            $query['return_to'] = $returnTo;
        }

        return $this->redirect->to('/login' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function pathOf(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

        return ReturnTo::sanitize($path);
    }

    private function refererPath(ServerRequestInterface $request): ?string
    {
        return ReturnTo::fromReferer($request->getHeaderLine('Referer'), $request->getUri()->getHost());
    }
}
