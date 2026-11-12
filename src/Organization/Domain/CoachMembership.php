<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use DateTimeImmutable;

/** A Coach's membership of one Trainer's organization; at most one is active per Coach (AC-59). */
final readonly class CoachMembership
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $coachUserId,
        public DateTimeImmutable $startedAt,
    ) {}
}
