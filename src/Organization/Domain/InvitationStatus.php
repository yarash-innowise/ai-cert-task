<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/** What the Trainer sees for each invitation (AC-58). Derived from the row and the clock, never stored. */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
        };
    }
}
