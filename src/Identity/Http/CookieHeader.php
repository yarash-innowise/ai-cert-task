<?php

declare(strict_types=1);

namespace App\Identity\Http;

/**
 * Builds Set-Cookie values. Every cookie is `__Host-` prefixed: Secure, Path=/, no Domain.
 */
final class CookieHeader
{
    public static function set(string $name, string $value, ?int $maxAge = null, bool $httpOnly = true): string
    {
        $parts = [$name . '=' . $value];
        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . $maxAge;
        }
        $parts[] = 'Path=/';
        $parts[] = 'Secure';
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=Lax';

        return implode('; ', $parts);
    }

    public static function clear(string $name): string
    {
        return $name . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/; Secure; HttpOnly; SameSite=Lax';
    }
}
