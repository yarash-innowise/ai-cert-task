<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Ends a child's association with a Trainer (AC-35): the parent first sees a confirmation
 * ({@see self::preview()}); on confirm the association is ended (soft delete, history kept) and the
 * cancellation of upcoming RSVPs is queued through the outbox, atomically with the change.
 */
final class RemoveChildFromTrainer
{
    public const CANCEL_TOPIC = 'event_registrations.cancel_upcoming';

    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly PlayerProfilePolicy $policy,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws NotFound not the actor's profile, or no active association with that Trainer */
    public function preview(CurrentUser $actor, string $profileId, string $organizationId): RemovalPreview
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);
        if ($profile === null || !$this->policy->canManage($actor, $profile)) {
            throw new NotFound();
        }
        $association = $this->associations->activeAssociation($profile->id, $organizationId);

        return $association === null ? throw new NotFound() : new RemovalPreview($profile, $association);
    }

    /** @throws NotFound as {@see self::preview()} */
    public function execute(CurrentUser $actor, string $profileId, string $organizationId, ?string $ip = null): RemovalPreview
    {
        $preview = $this->preview($actor, $profileId, $organizationId);

        $this->transaction->run(function () use ($actor, $preview, $ip): void {
            $profileId = $preview->profile->id;
            $organizationId = $preview->association->organizationId;
            if (!$this->associations->end($profileId, $organizationId, $actor->id, 'removed_by_parent', $this->clock->now())) {
                return; // a concurrent request already ended it
            }
            $this->outbox->enqueueJob(self::CANCEL_TOPIC, ['profile_id' => $profileId, 'organization_id' => $organizationId]);
            $this->audit->log('child.removed_from_trainer', $actor->id, 'player_profile', $profileId, $organizationId, ['profile_id' => $profileId, 'organization_id' => $organizationId], null, $ip);
        });

        return $preview;
    }
}
