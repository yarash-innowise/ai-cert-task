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
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A signed-in player opens a Trainer's link (AC-21). An adult with only a "Me" profile (or a
 * parent with no children) is associated instantly; one who already belongs to the Trainer gets
 * "already associated" and no duplicate; a parent with children must answer the checklist
 * ({@see JoinFamilyChecklist}). Children never come through here: their login is blocked (AC-49).
 */
final class JoinAsPlayer
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly RecordLinkUse $recordUse,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws Forbidden a child login, or not a player account */
    public function execute(CurrentUser $actor, ResolvedShareLink $resolved, ?string $ip = null): JoinResult
    {
        ChildCapabilities::assertMayUseLinks($actor);
        $link = $resolved->link;
        $self = $this->profiles->findSelf($actor->id);
        $children = $this->profiles->childrenOf($actor->id);

        if ($children !== []) {
            $ids = $this->familyIds($self, $children);

            return $this->allJoined($ids, $link->organizationId) ? JoinResult::alreadyAssociated($ids[0]) : JoinResult::chooseWho();
        }
        if ($self === null) {
            return JoinResult::needsProfile();
        }
        if ($this->associations->activeAssociation($self->id, $link->organizationId) !== null) {
            return JoinResult::alreadyAssociated($self->id);
        }

        $this->transaction->run(function () use ($actor, $self, $link, $ip): void {
            if ($this->associations->associate($self->id, $link->organizationId, $link->id, $this->clock->now())) {
                $this->recordUse->execute($link, $actor->id, $self->id, 'joined');
                $this->audit->log('player.joined_via_link', $actor->id, 'player_profile', $self->id, $link->organizationId, ['profile_id' => $self->id, 'share_link_id' => $link->id], null, $ip);
            }
        });

        return JoinResult::joined($self->id);
    }

    /**
     * Read-only: does this signed-in adult have to answer the "Who will train?" checklist? Used by the
     * GET landing page, which must not change anything (F-04).
     */
    public function needsChecklist(CurrentUser $actor, ResolvedShareLink $resolved): bool
    {
        ChildCapabilities::assertMayUseLinks($actor);
        $children = $this->profiles->childrenOf($actor->id);
        if ($children === []) {
            return false;
        }
        $ids = $this->familyIds($this->profiles->findSelf($actor->id), $children);

        return !$this->allJoined($ids, $resolved->link->organizationId);
    }

    /**
     * @param list<\App\Family\Domain\PlayerProfile> $children
     * @return list<string>
     */
    private function familyIds(?\App\Family\Domain\PlayerProfile $self, array $children): array
    {
        return array_map(static fn($p): string => $p->id, [...($self === null ? [] : [$self]), ...$children]);
    }

    /** @param list<string> $ids */
    private function allJoined(array $ids, string $organizationId): bool
    {
        $taken = $this->associations->activeForProfiles($ids);
        foreach ($ids as $id) {
            if (!$this->belongs($taken[$id] ?? [], $organizationId)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<\App\Family\Domain\TrainerAssociation> $associations */
    private function belongs(array $associations, string $organizationId): bool
    {
        foreach ($associations as $association) {
            if ($association->organizationId === $organizationId) {
                return true;
            }
        }

        return false;
    }
}
