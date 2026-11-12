<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\Session;
use App\Identity\Domain\SessionPolicy;
use App\Identity\Domain\SessionRepository;
use App\Shared\Domain\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the server-side session named by the `__Host-sid` cookie (only its SHA-256 is stored),
 * enforces idle/absolute timeouts, throttles `last_seen_at` writes and writes the cookie back
 * when a handler started, regenerated or ended the session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const COOKIE = '__Host-sid';

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly SessionPolicy $policy,
        private readonly Clock $clock,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = $this->clock->now();
        $session = null;
        $clear = false;
        $expired = false;

        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            $session = $this->sessions->find(Session::hashToken($cookie));
            if ($session === null) {
                $clear = true;
            } elseif ($this->policy->isExpired($session, $now)) {
                $this->sessions->delete($session->tokenHash);
                $session = null;
                $clear = true;
                $expired = true;
            } elseif ($this->policy->shouldTouch($session, $now)) {
                $this->sessions->touch($session->tokenHash, $now);
                $session = $session->withLastSeen($now);
            }
        }

        $state = new SessionState($this->sessions, $this->clock, $session, $clear);
        $request = $request
            ->withAttribute('session_state', $state)
            ->withAttribute('session_expired', $expired);

        $response = $handler->handle($request);

        $token = $state->issuedToken();
        if ($token !== null) {
            return $response->withAddedHeader('Set-Cookie', CookieHeader::set(self::COOKIE, $token, SessionPolicy::COOKIE_MAX_AGE));
        }
        if ($state->shouldClearCookie()) {
            return $response->withAddedHeader('Set-Cookie', CookieHeader::clear(self::COOKIE));
        }

        return $response;
    }
}
