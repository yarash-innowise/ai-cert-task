<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Deleted => 'Deleted',
        };
    }
}
