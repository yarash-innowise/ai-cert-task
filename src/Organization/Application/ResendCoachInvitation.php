<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Organization\Domain\CoachInvitation;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\Policy\CoachInvitationPolicy;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Resend = revoke the old invitation, create a new one (new 7 days, new link) and email it (AC-60).
 * Works for pending and expired invitations; an accepted one cannot be resent.
 */
final class ResendCoachInvitation
{
    public function __construct(
        private readonly CoachInvitationRepository $invitations,
        private readonly IssueCoachInvitation $issue,
        private readonly CoachInvitationPolicy $policy,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws NotFound no such invitation in the Trainer's organization (another Trainer's id looks the same)
     * @throws Forbidden not the Trainer of the organization
     * @throws Conflict the invitation was already accepted
     */
    public function execute(CurrentUser $actor, RequestContext $context, string $invitationId, ?string $ip = null): CoachInvitation
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $this->transaction->run(function () use ($actor, $organization, $invitationId, $ip): CoachInvitation {
            $old = $this->invitations->findInOrganization($organization, $invitationId, true) ?? throw new NotFound();
            if ($old->acceptedAt !== null) {
                throw new Conflict('This invitation was already accepted, so it cannot be sent again.');
            }
            $this->invitations->revoke($organization, $old->id, $this->clock->now());

            return $this->issue->execute($organization, $actor->id, Email::fromString($old->email), $old->name, $old->message, $ip);
        });
    }
}
