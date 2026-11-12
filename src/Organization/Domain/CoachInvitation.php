<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use DateTimeImmutable;

/**
 * A single-use Coach invitation of one Trainer's organization, valid 7 days from creation (AC-18, AC-57).
 * The status is derived: Accepted when used, Expired when the 7 days are over, otherwise Pending, so no
 * expiry job is needed and the answer is right even when no job has run. A revoked invitation (replaced
 * by a resend) is never usable.
 */
final readonly class CoachInvitation
{
    public const VALID_FOR = 'P7D';

    public function __construct(
        public string $id,
        public string $organizationId,
        public string $email,
        public ?string $name,
        public ?string $message,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    public function statusAt(DateTimeImmutable $now): InvitationStatus
    {
        if ($this->acceptedAt !== null) {
            return InvitationStatus::Accepted;
        }

        return $this->expiresAt <= $now || $this->revokedAt !== null ? InvitationStatus::Expired : InvitationStatus::Pending;
    }

    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->statusAt($now) === InvitationStatus::Pending;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
