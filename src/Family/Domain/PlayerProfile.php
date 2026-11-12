<?php

declare(strict_types=1);

namespace App\Family\Domain;

use DateTimeImmutable;

final readonly class PlayerProfile
{
    public function __construct(
        public string $id,
        public string $ownerUserId,
        public ProfileKind $kind,
        public ?string $loginUserId,
        public string $firstName,
        public string $lastName,
        /** Null for an anonymized player (deleted account): the age is then unknown. */
        public ?DateTimeImmutable $birthDate,
        public ?Gender $gender,
        public ?string $school,
        public bool $tokenSpendWithoutApproval,
        public ?int $jerseyNumber = null,
        public ?string $photoPath = null,
        public ?string $skillLevel = null,
    ) {}

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** @return int|null whole years, or null when the birth date is unknown (anonymized player) */
    public function age(DateTimeImmutable $today): ?int
    {
        return $this->birthDate === null ? null : Age::ofBirthDate($this->birthDate, $today)->years;
    }

    public function isChild(): bool
    {
        return $this->kind === ProfileKind::Child;
    }
}
