<?php

declare(strict_types=1);

namespace App\Organization\Application;

use DateTimeImmutable;

/** One player (a "Me" or a child profile) on a Trainer's roster; `inactive` is the "Inactive" marker of AC-74. */
final readonly class RosterPlayer
{
    public function __construct(
        public string $profileId,
        public string $name,
        public bool $isChild,
        /** Null when the birth date is unknown (anonymized player). */
        public ?int $age,
        public string $accountHolderName,
        public DateTimeImmutable $associatedSince,
        public bool $inactive,
    ) {}
}
