<?php

declare(strict_types=1);

namespace App\Integration\Application;

/** Port to token spending (Epic-05). Epic-01 binding: UnavailableTokenSpend (never spends). */
interface TokenSpend
{
    public function spend(string $payerUserId, int $tokens, string $reference): SpendResult;
}
