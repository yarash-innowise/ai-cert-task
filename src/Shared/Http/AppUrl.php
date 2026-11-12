<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Absolute links for emails; the base URL comes from configuration, never from the request.
 */
final class AppUrl
{
    public function __construct(private readonly string $baseUrl) {}

    public function absolute(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
