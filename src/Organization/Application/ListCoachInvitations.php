<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\Policy\CoachInvitationPolicy;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/** The Trainer's invitations with their derived status Pending / Accepted / Expired (AC-58). */
final class ListCoachInvitations
{
    public function __construct(
        private readonly CoachInvitationRepository $invitations,
        private readonly CoachInvitationPolicy $policy,
        private readonly Clock $clock,
    ) {}

    /** @return list<CoachInvitationItem> */
    public function execute(CurrentUser $actor, RequestContext $context): array
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }
        $now = $this->clock->now();

        return array_map(
            fn($invitation): CoachInvitationItem => new CoachInvitationItem($invitation, $invitation->statusAt($now)),
            $this->invitations->listForOrganization($organization),
        );
    }
}
