<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/** Why a Coach invitation cannot be used; every case has a clear message and associates nobody (AC-26, AC-59, AC-60). */
enum InvitationRefusal: string
{
    case Unknown = 'unknown';
    case AlreadyUsed = 'already_used';
    case Expired = 'expired';
    case Replaced = 'replaced';
    case WrongAccount = 'wrong_account';
    case NotACoachAccount = 'not_a_coach_account';
    case AccountExists = 'account_exists';
    case CoachAlreadyActive = 'coach_already_active';

    public function message(): string
    {
        return match ($this) {
            self::Unknown => 'This invitation link is not valid. Ask your Trainer to send you a new invitation.',
            self::AlreadyUsed => 'This invitation has already been used.',
            self::Expired => 'This invitation has expired. Ask your Trainer to send you a new invitation.',
            self::Replaced => 'This invitation was replaced by a newer one. Use the link in the newest invitation email, or ask your Trainer to resend it.',
            self::WrongAccount => 'This invitation was sent to a different email address. Sign in with the invited address to accept it.',
            self::NotACoachAccount => 'This email address belongs to an account that is not a Coach account, so it cannot accept a Coach invitation.',
            self::AccountExists => 'An account with this email address already exists. Sign in with it to accept the invitation.',
            self::CoachAlreadyActive => CoachAlreadyActive::MESSAGE,
        };
    }
}
