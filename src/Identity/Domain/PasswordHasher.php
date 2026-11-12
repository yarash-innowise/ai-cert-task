<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Argon2id hashing (design D5). Hashes are never logged or shown.
 */
final class PasswordHasher
{
    /**
     * Precomputed Argon2id hash (PHP defaults m=65536,t=4,p=1: the production options) of a throwaway
     * string, verified against when the email is unknown so that path costs what a real check costs.
     * A constant, so no password_hash() runs per request. If the production options change, regenerate
     * it; PasswordHasherTest fails while it no longer matches the default options.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$LzJzVlc4TERoNzAzWGh5SQ$g431lomqH5CT2gsTAyYZeLoeM6/QeskJgL8Mp+/JsLM';

    /** @param array{memory_cost?: int, time_cost?: int, threads?: int} $options */
    public function __construct(private readonly array $options = []) {}

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /** Spends the same time as a real verification so unknown emails are not distinguishable by timing. */
    public function dummyVerify(string $plain): void
    {
        password_verify($plain, self::DUMMY_HASH);
    }
}
