<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Test seam: an unqualified password_hash() call inside App\Identity\Domain resolves to this
 * function first, so tests can prove a code path never hashes. It counts and delegates.
 *
 * @param array<string, mixed> $options
 */
function password_hash(string $password, string|int|null $algo, array $options = []): string
{
    $GLOBALS['password_hash_calls'] = (int) ($GLOBALS['password_hash_calls'] ?? 0) + 1;

    return \password_hash($password, $algo, $options);
}
