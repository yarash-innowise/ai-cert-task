<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure;

use App\Integration\Application\TokenBalance;

/** Stand-in until Epic-05 exists: everybody has no tokens. */
final class ZeroTokenBalance implements TokenBalance
{
    public function balanceOf(string $userId): int
    {
        return 0;
    }
}
