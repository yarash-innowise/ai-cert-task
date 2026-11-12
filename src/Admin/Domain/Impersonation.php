<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateInterval;
use DateTimeImmutable;

final readonly class Impersonation
{
    /** The fixed length of an impersonation (AC-54). */
    public const MAX_DURATION = 'PT1H';

    public function __construct(
        public string $id,
        public string $adminUserId,
        public string $targetUserId,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastActivityAt,
        public ?DateTimeImmutable $endedAt = null,
        public ?ImpersonationEndReason $endReason = null,
    ) {}

    public function expiresAt(): DateTimeImmutable
    {
        return $this->startedAt->add(new DateInterval(self::MAX_DURATION));
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt() <= $now;
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }
}
