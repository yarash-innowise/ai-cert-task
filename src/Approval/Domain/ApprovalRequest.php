<?php

declare(strict_types=1);

namespace App\Approval\Domain;

use DateTimeImmutable;

/** A child's purchase, token spend, RSVP or RSVP cancellation waiting for the parent (AC-38). */
final readonly class ApprovalRequest
{
    public const VALID_FOR = 'PT48H';

    /** @param array<string, mixed> $ruleSnapshot the rules in force when the child asked */
    public function __construct(
        public string $id,
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
        public ApprovalStatus $status,
        public array $ruleSnapshot,
        public ?string $childNote,
        public ?string $parentNote,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $decidedAt,
        public int $version,
    ) {}

    public function expiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** "USD 25.00", or null when nothing is paid. */
    public function amountLabel(): ?string
    {
        if ($this->paymentType === PaymentType::None) {
            return null;
        }
        if ($this->paymentType === PaymentType::Tokens) {
            return $this->amountMinor . ' tokens';
        }

        return sprintf('%s %d.%02d', $this->currency, intdiv($this->amountMinor, 100), $this->amountMinor % 100);
    }

    public function eventLabel(): string
    {
        return $this->eventTitle ?? 'Event ' . substr($this->eventRef, 0, 8);
    }

    /** Whether the rules in force when the child asked allowed token spending without approval. */
    public function snapshotAllowsTokensWithoutApproval(): bool
    {
        return ($this->ruleSnapshot['token_spend_without_approval'] ?? false) === true;
    }
}
