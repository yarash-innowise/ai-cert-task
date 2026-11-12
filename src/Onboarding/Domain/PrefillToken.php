<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** The secret in a pre-fill link: 256 random bits, base64url (43 characters); only its SHA-256 is stored. */
final class PrefillToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
