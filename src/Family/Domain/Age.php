<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Shared\Http\Exception\ValidationFailed;
use DateTimeImmutable;

/** Age in whole years, derived from a birth date (Q-01.02); a child must be 0 to 17 (Q-01.03). */
final readonly class Age
{
    public const CHILD_MIN = 0;
    public const CHILD_MAX = 17;
    public const ADULT = 18;

    private function __construct(public int $years) {}

    /** @throws ValidationFailed when the birth date is in the future */
    public static function ofBirthDate(DateTimeImmutable $birthDate, DateTimeImmutable $today, string $field = 'birth_date'): self
    {
        $birth = $birthDate->setTime(0, 0);
        $now = $today->setTime(0, 0);
        if ($birth > $now) {
            throw ValidationFailed::forField($field, 'Birth date cannot be in the future.');
        }

        return new self($birth->diff($now)->y);
    }

    public function isChildAge(): bool
    {
        return $this->years >= self::CHILD_MIN && $this->years <= self::CHILD_MAX;
    }

    public function isAdult(): bool
    {
        return $this->years >= self::ADULT;
    }

    public static function childRangeMessage(): string
    {
        return sprintf('A child must be between %d and %d years old. Check the birth date.', self::CHILD_MIN, self::CHILD_MAX);
    }
}
