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
use App\Onboarding\Domain\RegistrationPrefill;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Registration through a camp-form pre-fill link (AC-85). With a usable pre-fill, ONE transaction
 * writes the account (player, unverified), the player profile, the association with the pre-fill's
 * Trainer, consumes the token and queues the confirmation and verification emails. Without one
 * (tampered, expired, consumed) it is an ordinary registration: account and profile only.
 * An email that already has an account creates nothing and throws {@see EmailAlreadyRegistered}:
 * the visitor is asked to sign in, and the pre-fill is attached only after that (AttachPrefill).
 */
final class RegisterFromPrefill
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly RegistrationPrefillRepository $prefills,
        private readonly IssueEmailVerification $verification,
        private readonly NotificationService $notifications,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws ValidationFailed the password or the age for the chosen player kind is refused
     * @throws EmailAlreadyRegistered the email already has an account; nothing was written
     */
    public function execute(?RegistrationPrefill $prefill, RegisterInput $input, ?string $ip = null): RegistrationResult
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
            throw new EmailAlreadyRegistered();
        }
        $hash = $this->hasher->hash($input->password);

        return $this->transaction->run(function () use ($prefill, $input, $hash, $now, $ip): RegistrationResult {
            $user = $this->users->create($input->email, $input->name, Role::Player, $hash, null, false, $input->phone->e164);
            $profile = $this->createProfile($user->id, $input);
            $businessName = '';
            $organizationId = null;
            if ($prefill !== null) {
                $organization = OrganizationId::fromString($prefill->organizationId);
                // One use only: a concurrent registration with the same token loses here and rolls back as an ordinary one.
                if ($this->prefills->consume($organization, $prefill->id, $now)) {
                    $this->associations->associate($profile->id, $prefill->organizationId, null, $now);
                    $businessName = $prefill->businessName;
                    $organizationId = $prefill->organizationId;
                }
            }
            $this->audit->log(
                $organizationId === null ? 'player.registered' : 'player.registered_via_prefill',
                $user->id,
                'player_profile',
                $profile->id,
                $organizationId,
                ['profile_id' => $profile->id, 'trainer' => $businessName],
                null,
                $ip,
            );
            $this->notifications->email(new EmailMessage('registration-confirmation', $input->email->value, [
                'name' => $input->name,
                'player_name' => $profile->fullName(),
                'business_name' => $businessName,
            ]));
            $this->verification->forUser($user->id);

            return new RegistrationResult($user->id, $profile->id, $businessName);
        });
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
}
