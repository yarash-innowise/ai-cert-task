<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\TrainerAssociations;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Outbox\Outbox;

/**
 * Ends every active Trainer association of the child profiles of a guardian who is being deleted (GDPR, Q-01.14);
 * history rows are kept and the cancellation of upcoming RSVPs is queued per ended association. Runs inside the
 * caller's transaction.
 */
final class EndGuardianAssociations
{
    public const REASON = 'guardian_deleted';

    public function __construct(
        private readonly TrainerAssociations $associations,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
    ) {}

    public function execute(string $guardianUserId, string $endedByUserId): void
    {
        $ended = $this->associations->endAllOfGuardianChildren($guardianUserId, $endedByUserId, self::REASON, $this->clock->now());
        foreach ($ended as $association) {
            $this->outbox->enqueueJob(RemoveChildFromTrainer::CANCEL_TOPIC, [
                'profile_id' => $association['profile_id'],
                'organization_id' => $association['organization_id'],
            ]);
        }
    }
}
