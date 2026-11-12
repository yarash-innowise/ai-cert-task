<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\UserLifecycleStore;
use App\Approval\Application\AutoDenyForGuardian;
use App\Identity\Domain\Role;
use App\Identity\Domain\SessionRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;

/**
 * What follows a user leaving the active state (Q-01.14, Q-01.15), inside the caller's transaction:
 *  - every session of the user ends;
 *  - a Trainer's organization becomes inactive (links invalid, context unavailable to its members);
 *  - a parent's pending approval requests are auto-denied and the child logins are deactivated with them.
 * Reactivating restores the organization / child logins explicitly together with the user.
 */
final class LifecycleCascade
{
    public function __construct(
        private readonly UserLifecycleStore $store,
        private readonly SessionRepository $sessions,
        private readonly AutoDenyForGuardian $autoDeny,
    ) {}

    public function deactivateDependents(User $target, string $actorUserId): void
    {
        $this->sessions->deleteAllForUser($target->id);

        if ($target->role === Role::Trainer) {
            $this->store->setOrganizationStatus($target->id, 'inactive');
        }
        if ($this->isGuardian($target)) {
            $this->autoDeny->execute($target->id, $actorUserId);
            foreach ($this->store->moveChildLogins($target->id, UserStatus::Active, UserStatus::Inactive) as $childId) {
                $this->sessions->deleteAllForUser($childId);
            }
        }
    }

    public function reactivateDependents(User $target): void
    {
        if ($target->role === Role::Trainer) {
            $this->store->setOrganizationStatus($target->id, 'active');
        }
        if ($this->isGuardian($target)) {
            $this->store->moveChildLogins($target->id, UserStatus::Inactive, UserStatus::Active);
        }
    }

    private function isGuardian(User $user): bool
    {
        return $user->role === Role::Player && $user->accountKind === 'standard';
    }
}
