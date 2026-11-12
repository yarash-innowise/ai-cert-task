<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use DateTimeImmutable;

/** A Trainer's static player link: belongs to exactly one organization, unlimited uses, no expiry (AC-18). */
final readonly class ShareLink
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $code,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public int $useCount,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}
}
