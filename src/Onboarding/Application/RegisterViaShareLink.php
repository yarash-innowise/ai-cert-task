<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Family\Domain\Age;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Application\IssueEmailVerification;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Onboarding\Domain\ResolvedShareLink;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A visitor registers through a Trainer's player link (AC-19, AC-20): in ONE transaction the
 * account (player role, unverified), the player profile ("Me" for an adult, "Child" for someone
 * under 18, who is always parent-managed, Q-01.03), the association with the link's Trainer and
 * the link use are written, and the confirmation and verification emails are queued. A duplicate
 * email in any letter case is refused and nothing at all is created (AC-6).
 */
final class RegisterViaShareLink
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly RecordLinkUse $recordUse,
        private readonly IssueEmailVerification $verification,
        private readonly NotificationService $notifications,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws ValidationFailed the password, the age for the chosen player kind, or the email (already registered) is refused */
    public function execute(ResolvedShareLink $resolved, RegisterInput $input, ?string $ip = null): RegistrationResult
    {
        $now = $this->clock->now();
        $age = Age::ofBirthDate($input->birthDate, $now);
        if ($input->playerIsChild && !$age->isChildAge()) {
            throw ValidationFailed::forField('birth_date', Age::childRangeMessage());
        }
        if (!$input->playerIsChild && !$age->isAdult()) {
            throw ValidationFailed::forField('birth_date', sprintf('You must be at least %d years old to register yourself. Choose "My child" and register as their parent.', Age::ADULT));
        }
        $this->passwordPolicy->assertAcceptable($input->password);
        if ($this->users->findByEmail($input->email) !== null) {
            throw self::emailTaken();
        }
        $hash = $this->hasher->hash($input->password);
        $link = $resolved->link;

        try {
            return $this->transaction->run(function () use ($resolved, $input, $hash, $now, $link, $ip): RegistrationResult {
                $user = $this->users->create($input->email, $input->name, Role::Player, $hash, null, false, $input->phone->e164);
                $profile = $this->createProfile($user->id, $input);
                $this->associations->associate($profile->id, $link->organizationId, $link->id, $now);
                $this->recordUse->execute($link, $user->id, $profile->id, 'registered');
                $this->audit->log('player.registered_via_link', $user->id, 'player_profile', $profile->id, $link->organizationId, ['share_link_id' => $link->id, 'profile_id' => $profile->id, 'trainer' => $resolved->businessName], null, $ip);
                $this->notifications->email(new EmailMessage('registration-confirmation', $input->email->value, [
                    'name' => $input->name,
                    'player_name' => $profile->fullName(),
                    'business_name' => $resolved->businessName,
                ]));
                $this->verification->forUser($user->id);

                return new RegistrationResult($user->id, $profile->id, $resolved->businessName);
            });
        } catch (EmailAlreadyRegistered) {
            throw self::emailTaken();
        }
    }

    private function createProfile(string $userId, RegisterInput $input): PlayerProfile
    {
        [$first, $last] = array_pad(explode(' ', trim($input->playerName), 2), 2, '');
        $last = trim($last);
        if ($input->playerIsChild) {
            return $this->profiles->createChild($userId, $first, $last, $input->birthDate, $input->gender, null);
        }

        return $this->profiles->createSelf($userId, $first, $last, $input->birthDate, $input->gender);
    }

    private static function emailTaken(): ValidationFailed
    {
        return ValidationFailed::forField('email', 'This email is already registered. Sign in instead.');
    }
}
