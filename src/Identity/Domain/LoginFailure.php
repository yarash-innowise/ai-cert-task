<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum LoginFailure: string
{
    case InvalidCredentials = 'invalid_credentials';
    case Locked = 'locked';
    case Deactivated = 'deactivated';
    case Unverified = 'unverified';
}
