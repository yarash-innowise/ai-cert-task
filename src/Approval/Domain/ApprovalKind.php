<?php

declare(strict_types=1);

namespace App\Approval\Domain;

/** What the child asked for. RSVP and RSVP cancellation use the same workflow, free events included (Q-01.09, AC-48). */
enum ApprovalKind: string
{
    case Purchase = 'purchase';
    case TokenSpend = 'token_spend';
    case Rsvp = 'rsvp';
    case RsvpCancel = 'rsvp_cancel';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'purchase',
            self::TokenSpend => 'token spend',
            self::Rsvp => 'RSVP',
            self::RsvpCancel => 'RSVP cancellation',
        };
    }
}
