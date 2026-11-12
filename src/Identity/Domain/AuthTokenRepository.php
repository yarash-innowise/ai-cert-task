<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

/**
 * One-time tokens: 256-bit random, only the SHA-256 is stored, single use (design D7).
 */
interface AuthTokenRepository
{
    /** Revokes the user's live tokens of this purpose and returns the new plaintext token (shown once). */
    public function issue(string $userId, AuthTokenPurpose $purpose, DateTimeImmutable $now): string;

    /** @return string|null the user id when the token is live (unused, not revoked, not expired); nothing is changed */
    public function peek(string $token, AuthTokenPurpose $purpose, DateTimeImmutable $now): ?string;

    /** Atomically uses the token; @return string|null the user id, or null when used, revoked, expired or unknown */
    public function consume(string $token, AuthTokenPurpose $purpose, DateTimeImmutable $now): ?string;

    public function revokeLive(string $userId, AuthTokenPurpose $purpose, DateTimeImmutable $now): void;

    /** @return int rows removed */
    public function purgeOlderThan(DateTimeImmutable $before): int;
}
