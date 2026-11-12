<?php

declare(strict_types=1);

namespace App\Approval\Domain;

enum PaymentType: string
{
    case Usd = 'usd';
    case Tokens = 'tokens';
    /** An RSVP or its cancellation: nothing is paid. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Usd => 'USD',
            self::Tokens => 'Tokens',
            self::None => 'No payment',
        };
    }
}
