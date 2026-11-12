<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

interface SessionRepository
{
    public function find(string $tokenHash): ?Session;

    public function add(Session $session): void;

    public function touch(string $tokenHash, DateTimeImmutable $at): void;

    /** Replaces the identifier and CSRF token of a session in place (ID regeneration). */
    public function rotate(string $oldTokenHash, string $newTokenHash, string $newCsrfToken): void;

    /**
     * Remembers the chosen Trainer context for the rest of the session (AC-25).
     *
     * @param array<string, mixed>|null $context
     */
    public function setActiveContext(string $tokenHash, ?array $context): void;

    /**
     * Switches the user a session shows (impersonation start or end) and forgets the chosen context, which belongs to
     * the previous user. Pass null impersonation values to return to a normal session.
     */
    public function convert(string $tokenHash, string $userId, ?string $impersonationId, ?string $impersonatorUserId): void;

    public function delete(string $tokenHash): void;

    /** @return int number of sessions removed */
    public function deleteAllForUser(string $userId, ?string $exceptTokenHash = null): int;

    /** @return int number of sessions removed */
    public function purgeExpired(DateTimeImmutable $now): int;
}
