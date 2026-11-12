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
 * Super Admin reactivates an inactive user (AC-75), who can then sign in again. A deleted user never comes back
 * (AC-78). A parent's child logins and a Trainer's organization are reactivated with them (Q-01.14, Q-01.15); a
 * child login cannot be reactivated on its own while its parent is still inactive.
 */
final class ReactivateUser
{
    public function __construct(
        private readonly UserLifecycleStore $store,
        private readonly LifecycleCascade $cascade,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws NotFound unknown user
     * @throws ValidationFailed the user is deleted, already active, or a child login of an inactive parent
     */
    public function execute(string $userId, string $actorUserId, ?string $ip = null): User
    {
        return $this->transaction->run(function () use ($userId, $actorUserId, $ip): User {
            $target = $this->store->lock($userId) ?? throw new NotFound();
            if ($target->status === UserStatus::Deleted) {
                throw ValidationFailed::forField('user', 'A deleted user cannot be reactivated.');
            }
            if ($target->status === UserStatus::Active) {
                throw ValidationFailed::forField('user', 'This user is already active.');
            }
            $guardianStatus = $this->store->guardianStatusOf($target->id);
            if ($guardianStatus !== null && $guardianStatus !== UserStatus::Active) {
                throw ValidationFailed::forField('user', 'Reactivate the parent first: a child login needs an active parent.');
            }

            $this->store->setStatus($target->id, UserStatus::Active);
            $this->cascade->reactivateDependents($target);
            $this->audit->log('user.reactivated', $actorUserId, 'user', $target->id, null, ['user_id' => $target->id, 'role' => $target->role->value], null, $ip);

            return $target;
        });
    }
}
