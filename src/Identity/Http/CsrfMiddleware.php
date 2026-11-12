<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\ReturnTo;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Synchronizer-token CSRF protection for every non-safe method (no exempt routes).
 *
 * Signed-in users compare against the token stored in their session. Visitors without a session
 * (login, reset forms) use a `__Host-csrf` cookie compared with the submitted value (double submit).
 * In both cases the Origin/Referer, when sent, must be same-origin. Failure -> Forbidden (403).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const COOKIE = '__Host-csrf';
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfTokenHolder $holder,
        private readonly Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $request->getAttribute('session_state');
        $session = $state instanceof SessionState ? $state->session() : null;

        $newGuestToken = null;
        if ($session !== null) {
            $expected = $session->csrfToken;
        } else {
            $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
            if (is_string($cookie) && preg_match('/^[A-Za-z0-9_-]{43}$/', $cookie) === 1) {
                $expected = $cookie;
            } else {
                $expected = $newGuestToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            }
        }
        $this->holder->set($expected);

        if (!in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            if ($newGuestToken !== null || !$this->sameOrigin($request) || !$this->tokenMatches($request, $expected)) {
                // A form submitted after the session timed out: nothing is processed or replayed;
                // sign in again and come back to the page of the form (edge: timeout while filling a form).
                if ($session === null && $request->getAttribute('session_expired') === true && $this->sameOrigin($request)) {
                    $returnTo = ReturnTo::fromReferer($request->getHeaderLine('Referer'), $request->getUri()->getHost());

                    return $this->redirect->to('/login?' . http_build_query(['notice' => 'expired'] + ($returnTo === null ? [] : ['return_to' => $returnTo])));
                }

                throw new Forbidden('Invalid or missing CSRF token.');
            }
        }

        $response = $handler->handle($request);
        if ($newGuestToken !== null) {
            $response = $response->withAddedHeader('Set-Cookie', CookieHeader::set(self::COOKIE, $newGuestToken, null));
        }

        return $response;
    }

    private function tokenMatches(ServerRequestInterface $request, string $expected): bool
    {
        $body = $request->getParsedBody();
        $submitted = is_array($body) ? ($body[self::FIELD] ?? null) : null;
        if (!is_string($submitted) || $submitted === '') {
            $submitted = $request->getHeaderLine(self::HEADER);
        }

        return $submitted !== '' && hash_equals($expected, $submitted);
    }

    private function sameOrigin(ServerRequestInterface $request): bool
    {
        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value === '') {
                continue;
            }
            if ($header === 'Origin' && $value === 'null') {
                return false;
            }
            $parts = parse_url($value);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                return false;
            }

            return $this->originOf($parts['scheme'], $parts['host'], $parts['port'] ?? null)
                === $this->uriOrigin($request->getUri());
        }

        return true;
    }

    private function uriOrigin(UriInterface $uri): string
    {
        return $this->originOf($uri->getScheme(), $uri->getHost(), $uri->getPort());
    }

    private function originOf(string $scheme, string $host, ?int $port): string
    {
        $scheme = strtolower($scheme);
        $port ??= $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . strtolower($host) . ':' . $port;
    }
}
