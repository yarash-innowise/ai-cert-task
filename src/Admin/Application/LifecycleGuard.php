<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\UserLifecycleStore;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Http\Exception\ValidationFailed;

/** The refusals that deactivation and deletion share: nobody removes themselves or the last active Super Admin. */
final class LifecycleGuard
{
    public function __construct(private readonly UserLifecycleStore $store) {}

    /** @throws ValidationFailed */
    public function assertMayRemove(User $target, string $actorUserId, string $verb): void
    {
        if ($target->id === $actorUserId) {
            throw ValidationFailed::forField('user', sprintf('You cannot %s your own account.', $verb));
        }
        // Counted under lock, so two admins removing each other at the same moment cannot both pass.
        if ($target->role === Role::SuperAdmin && $target->isActive() && $this->store->lockActiveSuperAdmins() <= 1) {
            throw ValidationFailed::forField('user', sprintf('The last active Super Admin cannot be %s.', $verb === 'delete' ? 'deleted' : 'deactivated'));
        }
    }
}
