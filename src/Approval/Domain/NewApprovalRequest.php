<?php

declare(strict_types=1);

namespace App\Approval\Domain;

use DateTimeImmutable;

/** Everything needed to open a request; the id, status "pending" and version come from the database. */
final readonly class NewApprovalRequest
{
    /** @param array<string, mixed> $ruleSnapshot */
    public function __construct(
        public string $organizationId,
        public string $childProfileId,
        public string $childUserId,
        public string $parentUserId,
        public ApprovalKind $kind,
        public string $eventRef,
        public ?string $eventTitle,
        public int $amountMinor,
        public string $currency,
        public PaymentType $paymentType,
        public array $ruleSnapshot,
        public ?string $childNote,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
