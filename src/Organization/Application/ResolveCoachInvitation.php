<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\CoachInvitationToken;
use App\Organization\Domain\InvitationRefusal;
use App\Organization\Domain\InvitationRefused;
use App\Organization\Domain\ResolvedCoachInvitation;
use App\Shared\Domain\Clock;

/** Looks an invitation link up for its landing page; writes nothing (AC-26). */
final class ResolveCoachInvitation
{
    public function __construct(private readonly CoachInvitationRepository $invitations, private readonly Clock $clock) {}

    /** @return array{ResolvedCoachInvitation|null, InvitationRefusal|null} the invitation (null when unknown) and why it cannot be used (null when it can) */
    public function execute(string $token): array
    {
        $token = trim($token);
        $resolved = CoachInvitationToken::isWellFormed($token) ? $this->invitations->findByTokenHash(CoachInvitationToken::hash($token)) : null;
        if ($resolved === null) {
            return [null, InvitationRefusal::Unknown];
        }

        return [$resolved, InvitationRefused::reasonFor($resolved, $this->clock->now())];
    }
}
