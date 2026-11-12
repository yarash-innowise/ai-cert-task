<?php

declare(strict_types=1);

namespace App\Family\Domain;

enum ProfileKind: string
{
    /** The account holder training themselves ("Me"). */
    case Me = 'self';
    /** A child of the account holder ("Child"). */
    case Child = 'child';

    public function label(): string
    {
        return match ($this) {
            self::Me => 'Me',
            self::Child => 'Child',
        };
    }
}
