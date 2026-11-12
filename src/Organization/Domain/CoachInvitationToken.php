<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/** The secret in an invitation link: 256 random bits, only the SHA-256 is stored (design D7). */
final class CoachInvitationToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
