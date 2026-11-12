<?php

declare(strict_types=1);

namespace App\Family\Http;

use DateTimeImmutable;

/** Strict YYYY-MM-DD parsing (rejects 2020-02-31 style overflow). */
final class BirthDate
{
    public static function parse(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1900) {
            return null;
        }

        return $date;
    }
}
