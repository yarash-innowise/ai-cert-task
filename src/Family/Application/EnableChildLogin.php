<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * The parent gives a child their own login (G1, AC-37): a player account of kind "child" with the
 * parent as guardian, verified by the parent's action. Children cannot register themselves.
 */
final class EnableChildLogin
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly UserRepository $users,
        private readonly PlayerProfilePolicy $policy,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws Forbidden the actor may not manage a family
     * @throws NotFound the profile is not the actor's child
     * @throws ValidationFailed the child already has a login, the email is taken, or the password is refused
     */
    public function execute(CurrentUser $actor, string $profileId, Email $email, string $password, ?string $ip = null): void
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);
        if ($profile === null || !$this->policy->canEnableChildLogin($actor, $profile)) {
            throw new NotFound();
        }
        if ($profile->loginUserId !== null) {
            throw ValidationFailed::forField('email', 'This child already has a login.');
        }
        $this->passwordPolicy->assertAcceptable($password);
        $hash = $this->hasher->hash($password);

        try {
            $this->transaction->run(function () use ($actor, $profile, $email, $hash, $ip): void {
                $user = $this->users->create($email, $profile->fullName(), Role::Player, $hash, $this->clock->now(), false, null, 'child', $actor->id);
                if (!$this->profiles->attachLogin($profile->id, $user->id)) {
                    throw ValidationFailed::forField('email', 'This child already has a login.');
                }
                $this->audit->log('child_login.enabled', $actor->id, 'player_profile', $profile->id, null, ['profile_id' => $profile->id, 'login_user_id' => $user->id], null, $ip);
            });
        } catch (EmailAlreadyRegistered) {
            throw ValidationFailed::forField('email', 'This email is already registered.');
        }
    }
}
