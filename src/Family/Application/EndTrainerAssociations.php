<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\TrainerAssociations;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Outbox\Outbox;

/**
 * Ends every active player association with a Trainer who is being deleted (history rows are kept) and queues
 * the cancellation of upcoming RSVPs per ended association. Runs inside the caller's transaction.
 */
final class EndTrainerAssociations
{
    public const REASON = 'trainer_deleted';

    public function __construct(
        private readonly TrainerAssociations $associations,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
    ) {}

    public function execute(string $trainerUserId, string $endedByUserId): void
    {
        $ended = $this->associations->endAllOfTrainer($trainerUserId, $endedByUserId, self::REASON, $this->clock->now());
        foreach ($ended as $association) {
            $this->outbox->enqueueJob(RemoveChildFromTrainer::CANCEL_TOPIC, [
                'profile_id' => $association['profile_id'],
                'organization_id' => $association['organization_id'],
            ]);
        }
    }
}
