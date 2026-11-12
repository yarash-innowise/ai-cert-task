<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

interface RegistrationPrefillRepository
{
    /**
     * Tenant method: stores a pre-fill of the organization under the SHA-256 of its token.
     *
     * @param array<string, string> $fields
     */
    public function add(OrganizationId $organization, string $tokenHash, array $fields, DateTimeImmutable $expiresAt, DateTimeImmutable $at): void;

    /**
     * Public resolution by the secret token's hash (the one non-tenant lookup): only a pre-fill that is
     * not consumed, not expired, and whose organization and owning Trainer are active; otherwise null.
     */
    public function findUsableByHash(string $tokenHash, DateTimeImmutable $now): ?RegistrationPrefill;

    /** @return bool false when the pre-fill was already consumed (one use only, also under a race) */
    public function consume(OrganizationId $organization, string $id, DateTimeImmutable $at): bool;
}
