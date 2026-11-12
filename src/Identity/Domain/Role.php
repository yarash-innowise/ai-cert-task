<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Trainer = 'trainer';
    case Coach = 'coach';
    case Player = 'player';

    public function dashboardPath(): string
    {
        return match ($this) {
            self::SuperAdmin => '/admin',
            self::Trainer => '/trainer',
            self::Coach => '/coach',
            self::Player => '/family',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Trainer => 'Trainer',
            self::Coach => 'Coach',
            self::Player => 'Player/Parent',
        };
    }
}
