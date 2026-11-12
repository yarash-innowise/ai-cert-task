<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateInterval;

enum AuthTokenPurpose: string
{
    case PasswordReset = 'password_reset';
    case EmailVerification = 'email_verification';
    case TrainerSetup = 'trainer_setup';

    public function lifetime(): DateInterval
    {
        return new DateInterval(match ($this) {
            self::PasswordReset => 'PT1H',
            self::EmailVerification => 'PT24H',
            self::TrainerSetup => 'P7D',
        });
    }
}
