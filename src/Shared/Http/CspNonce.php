<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Holds the current request's CSP nonce. SecurityHeadersMiddleware renews it for
 * every request; the Twig function csp_nonce() reads it.
 */
final class CspNonce
{
    private string $value = '';

    public function renew(): string
    {
        return $this->value = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function value(): string
    {
        return $this->value !== '' ? $this->value : $this->renew();
    }
}
