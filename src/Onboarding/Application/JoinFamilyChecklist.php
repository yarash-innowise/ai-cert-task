<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Family\Domain\ChildCapabilities;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Onboarding\Domain\ResolvedShareLink;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * "Who will train with [Trainer]?" (AC-22): a checklist of Me (only when a Me profile exists) and
 * each child, all UNTICKED by default (so a child the parent removed earlier is never re-added
 * without a fresh choice). Only the ticked people are associated; ticking nobody associates nobody.
 */
final class JoinFamilyChecklist
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly RecordLinkUse $recordUse,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<ChecklistOption> Me first, then the children
     * @throws Forbidden a child login, or not a player account
     */
    public function options(CurrentUser $actor, ResolvedShareLink $resolved): array
    {
        ChildCapabilities::assertMayUseLinks($actor);
        $self = $this->profiles->findSelf($actor->id);
        $profiles = [...($self === null ? [] : [$self]), ...$this->profiles->childrenOf($actor->id)];
        $taken = $this->associations->activeForProfiles(array_map(static fn($p): string => $p->id, $profiles));

        $options = [];
        foreach ($profiles as $profile) {
            $already = false;
            foreach ($taken[$profile->id] ?? [] as $association) {
                $already = $already || $association->organizationId === $resolved->link->organizationId;
            }
            $options[] = new ChecklistOption($profile->id, $profile->isChild() ? $profile->fullName() : 'Me', !$profile->isChild(), $already);
        }

        return $options;
    }

    /**
     * @param list<string> $profileIds the ticked people
     * @return list<string> ids of the people associated just now (already associated ones are skipped, never duplicated)
     * @throws ValidationFailed nobody was ticked
     * @throws NotFound a ticked person is not the actor's
     */
    public function submit(CurrentUser $actor, ResolvedShareLink $resolved, array $profileIds, ?string $ip = null): array
    {
        $allowed = [];
        foreach ($this->options($actor, $resolved) as $option) {
            $allowed[$option->profileId] = $option;
        }
        $ticked = array_values(array_unique($profileIds));
        if ($ticked === []) {
            throw ValidationFailed::forField('profile_ids', 'Choose at least one person, or cancel.');
        }
        foreach ($ticked as $id) {
            if (!isset($allowed[$id])) {
                throw new NotFound();
            }
        }

        $link = $resolved->link;

        return $this->transaction->run(function () use ($actor, $ticked, $link, $ip): array {
            $added = [];
            foreach ($ticked as $profileId) {
                if ($this->associations->associate($profileId, $link->organizationId, $link->id, $this->clock->now())) {
                    $this->recordUse->execute($link, $actor->id, $profileId, 'joined');
                    $this->audit->log('player.joined_via_link', $actor->id, 'player_profile', $profileId, $link->organizationId, ['profile_id' => $profileId, 'share_link_id' => $link->id], null, $ip);
                    $added[] = $profileId;
                }
            }

            return $added;
        });
    }
}
