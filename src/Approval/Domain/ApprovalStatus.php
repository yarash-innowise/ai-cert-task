<?php

declare(strict_types=1);

namespace App\Approval\Domain;

/**
 * pending -> info_requested -> (approved -> completed | payment_failed) | denied | expired;
 * auto_denied when the guardian is deactivated or deleted. Only pending and info_requested can still
 * be decided by the parent (AC-45).
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case InfoRequested = 'info_requested';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case AutoDenied = 'auto_denied';
    case Completed = 'completed';
    case PaymentFailed = 'payment_failed';

    /** @return list<self> the statuses in which the parent can still decide */
    public static function decidable(): array
    {
        return [self::Pending, self::InfoRequested];
    }

    public function isDecidable(): bool
    {
        return in_array($this, self::decidable(), true);
    }

    /** What the child sees (AC-40: Pending to Confirmed, Denied, or info requested). */
    public function childLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending Parent Approval',
            self::InfoRequested => 'More information requested',
            self::Approved => 'Confirmed (payment pending)',
            self::Completed => 'Confirmed',
            self::Denied, self::AutoDenied => 'Denied',
            self::Expired => 'Expired (no answer in 48 hours)',
            self::PaymentFailed => 'Payment failed',
        };
    }

    /** What the parent sees. */
    public function parentLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for your decision',
            self::InfoRequested => 'You asked for more information',
            self::Approved => 'Approved (payment pending)',
            self::Completed => 'Approved and completed',
            self::Denied => 'Denied',
            self::AutoDenied => 'Denied automatically',
            self::Expired => 'Expired (denied automatically after 48 hours)',
            self::PaymentFailed => 'Approved, but the payment failed',
        };
    }
}
