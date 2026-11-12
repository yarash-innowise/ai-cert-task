<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\UserLifecycleStore;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Super Admin deactivates a user (AC-72, AC-73): status `inactive`, every session ends, and the cascades of
 * {@see LifecycleCascade} run in the same transaction. Nothing is deleted, so the user's history stays in rosters
 * and reports, marked "Inactive" (AC-74). The caller has already checked the actor is a Super Admin (route gate).
 */
final class DeactivateUser
{
    public function __construct(
        private readonly UserLifecycleStore $store,
        private readonly LifecycleGuard $guard,
        private readonly LifecycleCascade $cascade,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws NotFound unknown user
     * @throws ValidationFailed self, last Super Admin, already inactive or deleted
     */
    public function execute(string $userId, string $actorUserId, ?string $ip = null): User
    {
        return $this->transaction->run(function () use ($userId, $actorUserId, $ip): User {
            $target = $this->store->lock($userId) ?? throw new NotFound();
            if ($target->status === UserStatus::Deleted) {
                throw ValidationFailed::forField('user', 'A deleted user cannot be deactivated.');
            }
            if ($target->status === UserStatus::Inactive) {
                throw ValidationFailed::forField('user', 'This user is already inactive.');
            }
            $this->guard->assertMayRemove($target, $actorUserId, 'deactivate');

            $this->store->setStatus($target->id, UserStatus::Inactive);
            $this->cascade->deactivateDependents($target, $actorUserId);
            $this->audit->log('user.deactivated', $actorUserId, 'user', $target->id, null, ['user_id' => $target->id, 'role' => $target->role->value], null, $ip);

            return $target;
        });
    }
}
