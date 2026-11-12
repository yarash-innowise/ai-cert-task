<?php

declare(strict_types=1);

namespace App\Availability\Domain;

use DateTimeImmutable;

/** A day of the week, Monday = 0 .. Sunday = 6 (the weekday column of availability_slots). */
enum Weekday: int
{
    case Monday = 0;
    case Tuesday = 1;
    case Wednesday = 2;
    case Thursday = 3;
    case Friday = 4;
    case Saturday = 5;
    case Sunday = 6;

    public static function ofDate(DateTimeImmutable $date): self
    {
        return self::from(((int) $date->format('N')) - 1);
    }

    public function label(): string
    {
        return $this->name;
    }

    public function short(): string
    {
        return substr($this->name, 0, 3);
    }
}
