<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Family\Domain\Gender;
use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;
use DateTimeImmutable;

/** Validated registration form: the account holder plus the one player being registered. */
final readonly class RegisterInput
{
    public function __construct(
        public string $name,
        public Email $email,
        public string $password,
        public Phone $phone,
        public bool $playerIsChild,
        public string $playerName,
        public DateTimeImmutable $birthDate,
        public Gender $gender,
    ) {}
}
