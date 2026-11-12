<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Organization\Domain\Policy\RosterPolicy;
use App\Shared\Domain\Clock;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/** The Trainer's players: active associations of their own organization only (AC-10, AC-74). */
final class ListOrganizationPlayers
{
    public function __construct(
        private readonly OrganizationRoster $roster,
        private readonly RosterPolicy $policy,
        private readonly Clock $clock,
    ) {}

    /** @return list<RosterPlayer> */
    public function execute(CurrentUser $actor, RequestContext $context): array
    {
        return $this->roster->players($this->organizationOf($actor, $context), $this->clock->now());
    }

    /** @throws NotFound the profile is not on this Trainer's roster (another Trainer's id looks the same) */
    public function find(CurrentUser $actor, RequestContext $context, string $profileId): RosterPlayer
    {
        return $this->roster->player($this->organizationOf($actor, $context), $profileId, $this->clock->now()) ?? throw new NotFound();
    }

    private function organizationOf(CurrentUser $actor, RequestContext $context): OrganizationId
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canView($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $organization;
    }
}
