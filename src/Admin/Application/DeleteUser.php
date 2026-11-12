<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\UserLifecycleStore;
use App\Family\Application\EndGuardianAssociations;
use App\Family\Application\EndTrainerAssociations;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\ImageStore;

/**
 * Super Admin deletes (anonymizes) a user (AC-76..AC-79) for a privacy request: one transaction, the user row
 * locked. Personal data is removed, every row is kept so history and counts stay unchanged, and the audit
 * entry holds only the original user id, the deleter, the time (its own timestamp) and the reason (Q-01.13).
 * Photos and logos are removed from storage through the outbox once the transaction has committed.
 */
final class DeleteUser
{
    public const REASON_MAX_LENGTH = 500;
    public const PURGE_AVAILABILITY_TOPIC = 'availability.purge';

    public function __construct(
        private readonly UserLifecycleStore $store,
        private readonly LifecycleGuard $guard,
        private readonly LifecycleCascade $cascade,
        private readonly EndTrainerAssociations $endAssociations,
        private readonly EndGuardianAssociations $endGuardianAssociations,
        private readonly ImageStore $images,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws NotFound unknown user
     * @throws ValidationFailed no reason, self, last Super Admin, a Trainer with active coaches, or already deleted
     */
    public function execute(string $userId, string $reason, string $actorUserId, ?string $ip = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationFailed::forField('reason', 'Enter the reason for the deletion.');
        }
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationFailed::forField('reason', sprintf('The reason may have at most %d characters.', self::REASON_MAX_LENGTH));
        }

        $this->transaction->run(function () use ($userId, $reason, $actorUserId, $ip): void {
            $target = $this->store->lock($userId) ?? throw new NotFound();
            if ($target->role === Role::Trainer) {
                // Serialize with a concurrent invitation acceptance (it holds the organization FOR SHARE) before counting coaches
                $this->store->lockOrganizationOf($target->id);
            }
            $this->assertMayDelete($target, $actorUserId);

            // Dependents first (they read the user's data), then the personal data goes.
            $this->cascade->deactivateDependents($target, $actorUserId);
            // Players do not block the deletion: their associations end, history is kept (Q-01.15).
            if ($target->role === Role::Trainer) {
                $this->endAssociations->execute($target->id, $actorUserId);
                // Only memberships of deactivated coaches can be left (deletion is refused while one is active): they end with the Trainer (Q-01.15, AC-59)
                $this->store->endCoachMemberships($target->id);
            }
            // A deleted parent takes the child profiles and child logins with them (GDPR, Q-01.14): their Trainer
            // associations end, the rows stay as history.
            if ($target->role === Role::Player && $target->accountKind === 'standard') {
                $this->endGuardianAssociations->execute($target->id, $actorUserId);
            }
            $left = $this->store->anonymize($target->id);
            foreach ($left->photos as $photo) {
                $this->images->deletePhotoAfterCommit($this->outbox, $photo);
            }
            foreach ($left->logos as $logo) {
                $this->images->deleteLogoAfterCommit($this->outbox, $logo);
            }
            // Weekly availability belongs to another module: it erases it when it handles this job.
            if ($left->playerProfileIds !== []) {
                $this->outbox->enqueueJob(self::PURGE_AVAILABILITY_TOPIC, ['subject_type' => 'player_profile', 'subject_ids' => $left->playerProfileIds]);
            }
            if ($target->role === Role::Coach) {
                $this->outbox->enqueueJob(self::PURGE_AVAILABILITY_TOPIC, ['subject_type' => 'coach', 'subject_ids' => [$target->id]]);
            }

            $this->audit->log('user.deleted', $actorUserId, 'user', $target->id, null, [
                'original_user_id' => $target->id,
                'deleted_by' => $actorUserId,
                'reason' => $reason,
            ], null, $ip);
        });
    }

    private function assertMayDelete(User $target, string $actorUserId): void
    {
        if ($target->status === UserStatus::Deleted) {
            throw ValidationFailed::forField('user', 'This user is already deleted.');
        }
        $this->guard->assertMayRemove($target, $actorUserId, 'delete');
        if ($target->role === Role::Trainer && $this->store->activeCoachCount($target->id) > 0) {
            throw ValidationFailed::forField('user', 'This Trainer still has active coaches. Deactivate or reassign them first, then delete the Trainer.');
        }
    }
}
