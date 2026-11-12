<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\CspNonce;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSP with a per-request nonce, HSTS, nosniff and a same-origin referrer policy.
 * Headers already set by a handler (for example a stricter CSP on media) win.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CspNonce $nonce) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = $this->nonce->renew();
        $response = $handler->handle($request->withAttribute('csp_nonce', $nonce));

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data: blob:",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
        ]);

        $defaults = [
            'Content-Security-Policy' => $csp,
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
        ];
        foreach ($defaults as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
