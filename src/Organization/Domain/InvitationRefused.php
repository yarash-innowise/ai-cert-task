<?php

declare(strict_types=1);

namespace App\Organization\Domain;

final class InvitationRefused extends \RuntimeException
{
    public function __construct(public readonly InvitationRefusal $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason->message(), 0, $previous);
    }

    /** Why the invitation (found by its token) cannot be used right now, or null when it can. */
    public static function reasonFor(ResolvedCoachInvitation $resolved, \DateTimeImmutable $now): ?InvitationRefusal
    {
        $invitation = $resolved->invitation;

        return match (true) {
            $invitation->acceptedAt !== null => InvitationRefusal::AlreadyUsed,
            $invitation->isRevoked() => InvitationRefusal::Replaced,
            $invitation->expiresAt <= $now => InvitationRefusal::Expired,
            !$resolved->organizationActive => InvitationRefusal::Unknown,
            default => null,
        };
    }
}
