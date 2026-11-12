<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\CoachAssignment;
use App\Availability\Domain\CoachAssignments;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Organization\Domain\Policy\RosterPolicy;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/** Assignments of the Trainer's organization, and a Coach's own (overridden ones included, AC-67). */
final class ListAssignments
{
    public function __construct(
        private readonly CoachAssignments $assignments,
        private readonly RosterPolicy $policy,
    ) {}

    /** @return list<CoachAssignment> */
    public function forTrainer(CurrentUser $actor, RequestContext $context): array
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canView($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $this->assignments->forOrganization($organization);
    }

    /** @return list<CoachAssignment> */
    public function forCoach(CurrentUser $actor, RequestContext $context): array
    {
        if ($actor->role !== Role::Coach) {
            throw new Forbidden();
        }

        return $context->organizationId === null ? [] : $this->assignments->forCoach($context->organizationId, $actor->id);
    }
}
