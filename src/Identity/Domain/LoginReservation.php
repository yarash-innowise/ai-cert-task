<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

/**
 * Outcome of asking the throttle for one sign-in attempt: either the attempt is refused until a
 * moment, or one attempt slot is held (recorded as a failure until it is settled).
 */
final class LoginReservation
{
    private function __construct(
        public readonly ?DateTimeImmutable $lockedUntil,
        public readonly ?int $attemptId,
    ) {}

    public static function locked(DateTimeImmutable $until): self
    {
        return new self($until, null);
    }

    public static function granted(int $attemptId): self
    {
        return new self(null, $attemptId);
    }
}
