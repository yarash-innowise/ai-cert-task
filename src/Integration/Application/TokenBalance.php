<?php

declare(strict_types=1);

namespace App\Integration\Application;

/** Port to the token balance a user can see (Epic-05, AC-46 "view tokens"). Epic-01 binding: zero balance. */
interface TokenBalance
{
    public function balanceOf(string $userId): int;
}
