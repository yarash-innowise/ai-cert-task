<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Shared\Domain\Email;
use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

interface CoachInvitationRepository
{
    public function create(
        OrganizationId $organization,
        Email $email,
        ?string $name,
        ?string $message,
        string $createdBy,
        string $tokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): CoachInvitation;

    /** An invitation of this organization only (another organization's id is "not found"); `$lock` takes a row lock inside the caller's transaction. */
    public function findInOrganization(OrganizationId $organization, string $id, bool $lock = false): ?CoachInvitation;

    /** @return list<CoachInvitation> newest first; replaced (revoked) invitations are left out */
    public function listForOrganization(OrganizationId $organization): array;

    /** Revokes the invitation; @return bool false when it was already accepted or revoked */
    public function revoke(OrganizationId $organization, string $id, DateTimeImmutable $at): bool;

    /** Revokes every still-pending invitation of the organization to that email (a new one replaces them). */
    public function revokePendingFor(OrganizationId $organization, Email $email, DateTimeImmutable $at): int;

    /** Resolves a secret token (the token is the capability, so this is not organization scoped); `$lock` takes a row lock. */
    public function findByTokenHash(string $tokenHash, bool $lock = false): ?ResolvedCoachInvitation;

    /** Marks the invitation used; @return bool false when it was no longer open */
    public function markAccepted(string $id, string $userId, DateTimeImmutable $at): bool;
}
