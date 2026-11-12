<?php

declare(strict_types=1);

namespace App\Family\Domain;

use DateTimeImmutable;

/** An active link between a player profile and a Trainer's organization. */
final readonly class TrainerAssociation
{
    public function __construct(
        public string $profileId,
        public string $organizationId,
        public string $businessName,
        public DateTimeImmutable $associatedAt,
    ) {}
}
