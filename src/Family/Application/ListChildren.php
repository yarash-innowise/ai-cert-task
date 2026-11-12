<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;

/** Every child of the parent with age and Trainers (with association dates), plus "Me" when present (AC-33). */
final class ListChildren
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly PlayerProfilePolicy $policy,
        private readonly Clock $clock,
    ) {}

    public function execute(CurrentUser $actor): FamilyOverview
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $children = $this->profiles->childrenOf($actor->id);
        $me = $this->profiles->findSelf($actor->id);
        $ids = array_map(static fn($p): string => $p->id, $children);
        if ($me !== null) {
            $ids[] = $me->id;
        }
        $associations = $this->associations->activeForProfiles($ids);
        $now = $this->clock->now();
        $item = static fn($p): ChildListItem => new ChildListItem($p, $p->age($now), $associations[$p->id] ?? [], $p->loginUserId !== null);

        return new FamilyOverview($me === null ? null : $item($me), array_map($item, $children), $this->associations->trainersOfOwner($actor->id));
    }
}
