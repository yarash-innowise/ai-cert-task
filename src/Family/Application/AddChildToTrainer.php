<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Onboarding\Application\RecordLinkUse;
use App\Onboarding\Application\ResolveShareLink;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A parent associates one of their profiles (a child, or "Me") with a Trainer (AC-29, AC-34):
 * by entering a ShareLink code, or by picking a Trainer the family already trains with. A
 * re-association after a removal is always the parent's explicit choice and starts a new row.
 */
final class AddChildToTrainer
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly PlayerProfilePolicy $policy,
        private readonly ResolveShareLink $resolveLink,
        private readonly RecordLinkUse $recordUse,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @param list<string> $organizationIds
     * @return list<TrainerAddResult>
     * @throws NotFound the profile is not the actor's
     * @throws ValidationFailed a chosen Trainer is not one the family is associated with
     */
    public function byTrainers(CurrentUser $actor, string $profileId, array $organizationIds, ?string $ip = null): array
    {
        $profile = $this->owned($actor, $profileId);
        $known = [];
        foreach ($this->associations->trainersOfOwner($actor->id) as $option) {
            $known[$option->organizationId] = $option->businessName;
        }
        foreach ($organizationIds as $id) {
            if (!isset($known[$id])) {
                throw ValidationFailed::forField('organization_ids', 'Choose one of your Trainers.');
            }
        }

        return $this->transaction->run(function () use ($actor, $profile, $organizationIds, $known, $ip): array {
            $results = [];
            foreach (array_values(array_unique($organizationIds)) as $organizationId) {
                $added = $this->associations->associate($profile->id, $organizationId, null, $this->clock->now());
                if ($added) {
                    $this->audit->log('child.trainer_added', $actor->id, 'player_profile', $profile->id, $organizationId, ['profile_id' => $profile->id, 'organization_id' => $organizationId, 'via' => 'trainer'], null, $ip);
                }
                $results[] = new TrainerAddResult($known[$organizationId], $added);
            }

            return $results;
        });
    }

    /**
     * @throws NotFound the profile is not the actor's
     * @throws ValidationFailed the code is not a valid link (one generic message, nothing written)
     */
    public function byShareLink(CurrentUser $actor, string $profileId, string $code, ?string $ip = null): TrainerAddResult
    {
        $profile = $this->owned($actor, $profileId);
        $resolved = $this->resolveLink->execute($code);
        if ($resolved === null) {
            throw ValidationFailed::forField('code', 'This link is not valid.');
        }

        return $this->transaction->run(function () use ($actor, $profile, $resolved, $ip): TrainerAddResult {
            $link = $resolved->link;
            $added = $this->associations->associate($profile->id, $link->organizationId, $link->id, $this->clock->now());
            if ($added) {
                $this->recordUse->execute($link, $actor->id, $profile->id, 'profile_added');
                $this->audit->log('child.trainer_added', $actor->id, 'player_profile', $profile->id, $link->organizationId, ['profile_id' => $profile->id, 'organization_id' => $link->organizationId, 'share_link_id' => $link->id, 'via' => 'share_link'], null, $ip);
            }

            return new TrainerAddResult($resolved->businessName, $added);
        });
    }

    private function owned(CurrentUser $actor, string $profileId): PlayerProfile
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);

        return $profile !== null && $this->policy->canManage($actor, $profile) ? $profile : throw new NotFound();
    }
}
