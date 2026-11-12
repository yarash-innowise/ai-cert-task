<?php

declare(strict_types=1);

namespace App\Identity\Http;

/**
 * The current request's CSRF token, read by the Twig `csrf_field()` function.
 */
final class CsrfTokenHolder
{
    private string $token = '';

    public function set(string $token): void
    {
        $this->token = $token;
    }

    public function value(): string
    {
        return $this->token;
    }
}
