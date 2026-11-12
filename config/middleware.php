<?php

declare(strict_types=1);

use App\Admin\Http\ImpersonationMiddleware;
use App\Identity\Http\AuthenticationMiddleware;
use App\Identity\Http\CsrfMiddleware;
use App\Identity\Http\SessionMiddleware;
use App\Shared\Http\BrandingMiddleware;
use App\Shared\Http\ContextMiddleware;
use App\Shared\Http\Middleware\ErrorHandlerMiddleware;
use App\Shared\Http\Middleware\SecurityHeadersMiddleware;

/**
 * Global middleware pipeline, outermost first (design C2, with SecurityHeaders and ErrorHandler
 * swapped so error responses also get the headers). Later tasks append Session, Csrf,
 * Authentication, Impersonation, Context and Branding after ErrorHandler, in that order.
 *
 * @return list<class-string<Psr\Http\Server\MiddlewareInterface>>
 */
return [
    // Security headers wrap the error handler so error pages (403/404/500) carry the same headers and nonce.
    SecurityHeadersMiddleware::class,
    ErrorHandlerMiddleware::class,
    SessionMiddleware::class,
    CsrfMiddleware::class,
    AuthenticationMiddleware::class,
    ImpersonationMiddleware::class,
    ContextMiddleware::class,
    BrandingMiddleware::class,
];
