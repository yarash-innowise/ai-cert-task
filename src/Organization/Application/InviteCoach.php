<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\CoachInvitation;
use App\Organization\Domain\CoachMemberships;
use App\Organization\Domain\Policy\CoachInvitationPolicy;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/** The Trainer invites a Coach by email (AC-57): a unique single-use link valid 7 days, sent by email. */
final class InviteCoach
{
    public function __construct(
        private readonly IssueCoachInvitation $issue,
        private readonly CoachInvitationPolicy $policy,
        private readonly UserRepository $users,
        private readonly CoachMemberships $memberships,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws NotFound the actor has no active organization
     * @throws Forbidden not the Trainer of it
     * @throws ValidationFailed the person already is a Coach of this Trainer
     */
    public function execute(CurrentUser $actor, RequestContext $context, InviteCoachInput $input, ?string $ip = null): CoachInvitation
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $this->transaction->run(function () use ($actor, $organization, $input, $ip): CoachInvitation {
            $existing = $this->users->findByEmail($input->email);
            $membership = $existing === null ? null : $this->memberships->activeOf($existing->id);
            if ($membership !== null && $membership->organizationId === $organization->value) {
                throw ValidationFailed::forField('email', 'This person is already one of your coaches.');
            }

            return $this->issue->execute($organization, $actor->id, $input->email, $input->name, $input->message, $ip);
        });
    }
}
