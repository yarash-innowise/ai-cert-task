<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\CoachAlreadyActive;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\CoachInvitationToken;
use App\Organization\Domain\CoachMemberships;
use App\Organization\Domain\InvitationRefusal;
use App\Organization\Domain\InvitationRefused;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A Coach accepts an invitation (AC-18, AC-26, AC-57..AC-60). One transaction: the invitation row is
 * locked FOR UPDATE (a second use waits and then sees it used), the invitation must be unused,
 * unexpired and not replaced, the account is the invited email (a signed-in Coach of that email, or a
 * new account made here), and the membership is inserted; the partial unique index makes "active
 * under at most one Trainer" a database invariant, so of two pending invitations from two Trainers
 * the first accepted wins and the second is refused. A refusal rolls everything back: nobody is
 * associated and no account is created.
 */
final class AcceptCoachInvitation
{
    public function __construct(
        private readonly CoachInvitationRepository $invitations,
        private readonly CoachMemberships $memberships,
        private readonly UserRepository $users,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @param CurrentUser|null $actor the signed-in user, when there is one
     * @param CoachRegistration|null $registration required when nobody is signed in
     * @throws InvitationRefused with the reason, for every unusable invitation
     * @throws ValidationFailed the new account's name or password is refused
     */
    public function execute(string $token, ?CurrentUser $actor, ?CoachRegistration $registration = null, ?string $ip = null): AcceptedInvitation
    {
        $token = trim($token);
        if (!CoachInvitationToken::isWellFormed($token)) {
            throw new InvitationRefused(InvitationRefusal::Unknown);
        }
        $hash = CoachInvitationToken::hash($token);

        return $this->transaction->run(function () use ($hash, $actor, $registration, $ip): AcceptedInvitation {
            $now = $this->clock->now();
            // Lock order: organization (FOR SHARE), then invitation. The organization is looked up unlocked first; the
            // locked read below re-checks that it is still active, so a Trainer deleted meanwhile refuses this acceptance (F-09).
            $peek = $this->invitations->findByTokenHash($hash) ?? throw new InvitationRefused(InvitationRefusal::Unknown);
            $this->memberships->lockOrganization(OrganizationId::fromString($peek->invitation->organizationId));
            $resolved = $this->invitations->findByTokenHash($hash, true) ?? throw new InvitationRefused(InvitationRefusal::Unknown);
            $reason = InvitationRefused::reasonFor($resolved, $now);
            if ($reason !== null) {
                throw new InvitationRefused($reason);
            }
            $invitation = $resolved->invitation;

            $registered = false;
            if ($actor !== null) {
                if ($actor->role !== Role::Coach) {
                    throw new InvitationRefused(InvitationRefusal::NotACoachAccount);
                }
                if ($actor->email !== $invitation->email) {
                    throw new InvitationRefused(InvitationRefusal::WrongAccount);
                }
                $coachId = $actor->id;
            } else {
                $coachId = $this->registerCoach(Email::fromString($invitation->email), $registration, $invitation->name);
                $registered = true;
            }

            // A membership in a deactivated or deleted Trainer's organization does not hold the Coach back (AC-59)
            $this->memberships->endInInactiveOrganizations($coachId, $now);
            if ($this->memberships->activeOf($coachId) !== null) {
                throw new InvitationRefused(InvitationRefusal::CoachAlreadyActive);
            }
            try {
                $this->memberships->start(OrganizationId::fromString($invitation->organizationId), $coachId, $invitation->id, $now);
            } catch (CoachAlreadyActive $e) {
                throw new InvitationRefused(InvitationRefusal::CoachAlreadyActive, $e);
            }
            if (!$this->invitations->markAccepted($invitation->id, $coachId, $now)) {
                throw new InvitationRefused(InvitationRefusal::AlreadyUsed);
            }
            $this->audit->log('coach.invitation_accepted', $coachId, 'coach_invitation', $invitation->id, $invitation->organizationId, ['invitation_id' => $invitation->id, 'trainer' => $resolved->businessName, 'registered' => $registered], null, $ip);

            return new AcceptedInvitation($coachId, $resolved->businessName, $registered);
        });
    }

    private function registerCoach(Email $email, ?CoachRegistration $registration, ?string $invitedName): string
    {
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            throw new InvitationRefused($existing->role === Role::Coach ? InvitationRefusal::AccountExists : InvitationRefusal::NotACoachAccount);
        }
        $name = trim($registration->name ?? '');
        if ($name === '') {
            $name = trim((string) $invitedName);
        }
        if ($registration === null || $name === '') {
            throw ValidationFailed::forField('name', 'Your name is required.');
        }
        if (mb_strlen($name) > 200) {
            throw ValidationFailed::forField('name', 'Name must be 200 characters or fewer.');
        }
        $this->passwordPolicy->assertAcceptable($registration->password);

        try {
            // The invitation link was sent to this address, so the address counts as verified (D7).
            return $this->users->create($email, $name, Role::Coach, $this->hasher->hash($registration->password), $this->clock->now())->id;
        } catch (EmailAlreadyRegistered) {
            throw new InvitationRefused(InvitationRefusal::AccountExists);
        }
    }
}
