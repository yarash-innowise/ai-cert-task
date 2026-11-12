<?php

declare(strict_types=1);

namespace App\Organization\Application;

use DateTimeImmutable;

/** One Coach of a Trainer's organization; `inactive` is the "Inactive" marker of AC-74. */
final readonly class RosterCoach
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public DateTimeImmutable $activeSince,
        public bool $inactive,
    ) {}
}
