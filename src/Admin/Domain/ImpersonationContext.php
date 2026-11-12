<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;

/** What the banner and the read-only rule need to know about the impersonation of the current request. */
final readonly class ImpersonationContext
{
    public function __construct(
        public string $id,
        public string $adminUserId,
        public string $adminName,
        public string $targetUserId,
        public string $targetName,
        public string $targetRoleLabel,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public bool $readOnly,
    ) {}
}
