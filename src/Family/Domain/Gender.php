<?php

declare(strict_types=1);

namespace App\Family\Domain;

enum Gender: string
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Other => 'Other',
        };
    }
}
