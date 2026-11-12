<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Organization\Domain\Policy\RosterPolicy;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/** The Trainer's Coaches: accepted invitations appear here (AC-58); only this organization's coaches (AC-10, AC-74). */
final class ListOrganizationCoaches
{
    public function __construct(
        private readonly OrganizationRoster $roster,
        private readonly RosterPolicy $policy,
    ) {}

    /** @return list<RosterCoach> */
    public function execute(CurrentUser $actor, RequestContext $context): array
    {
        return $this->roster->coaches($this->organizationOf($actor, $context));
    }

    /** @throws NotFound the user is not a Coach of this Trainer's organization (another Trainer's coach looks the same) */
    public function find(CurrentUser $actor, RequestContext $context, string $coachUserId): RosterCoach
    {
        return $this->roster->coach($this->organizationOf($actor, $context), $coachUserId) ?? throw new NotFound();
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
