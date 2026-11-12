<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Open-redirect guard for `return_to`: only a same-origin relative path is accepted.
 */
final class ReturnTo
{
    public static function sanitize(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        if ($value[0] !== '/' || (isset($value[1]) && ($value[1] === '/' || $value[1] === '\\'))) {
            return null;
        }
        if (str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $value;
    }

    /** Path (and query) of a Referer header when it points at the given host, else null. */
    public static function fromReferer(string $referer, string $host): ?string
    {
        $parts = $referer === '' ? false : parse_url($referer);
        if ($parts === false || !isset($parts['host']) || strtolower($parts['host']) !== strtolower($host)) {
            return null;
        }

        return self::sanitize(($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''));
    }
}
