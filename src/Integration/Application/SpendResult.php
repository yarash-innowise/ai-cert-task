<?php

declare(strict_types=1);

namespace App\Integration\Application;

enum SpendResult: string
{
    case Spent = 'spent';
    case InsufficientBalance = 'insufficient_balance';
    /** The tokens capability is not available; nothing was spent. */
    case Unavailable = 'unavailable';
}
