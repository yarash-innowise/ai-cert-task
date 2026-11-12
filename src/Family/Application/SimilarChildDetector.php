<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use DateTimeImmutable;

/** Finds an existing child of the same parent that the new one resembles (AC-30). */
final class SimilarChildDetector
{
    private const MAX_DISTANCE = 2;

    public function __construct(private readonly PlayerProfileRepository $profiles) {}

    public function find(string $ownerUserId, string $firstName, string $lastName, DateTimeImmutable $birthDate): ?PlayerProfile
    {
        $name = self::fold($firstName . ' ' . $lastName);
        foreach ($this->profiles->childrenOf($ownerUserId) as $existing) {
            if ($existing->birthDate === null || $existing->birthDate->format('Y') !== $birthDate->format('Y')) {
                continue;
            }
            $other = self::fold($existing->firstName . ' ' . $existing->lastName);
            if ($other === $name || levenshtein($other, $name) <= self::MAX_DISTANCE) {
                return $existing;
            }
        }

        return null;
    }

    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $ascii === false || $ascii === '' ? $value : $ascii;
    }
}
