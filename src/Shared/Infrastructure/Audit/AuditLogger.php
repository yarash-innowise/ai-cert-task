<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

/**
 * Append-only record of sensitive operations. Payloads carry IDs and
 * non-personal business fields only (design D14).
 */
interface AuditLogger
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function log(
        string $action,
        ?string $actorUserId,
        string $subjectType,
        ?string $subjectId = null,
        ?string $organizationId = null,
        array $payload = [],
        ?string $impersonatorUserId = null,
        ?string $ip = null,
    ): void;
}
