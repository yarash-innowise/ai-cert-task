<?php

declare(strict_types=1);

namespace App\Availability\Domain\Policy;

use App\Availability\Domain\CoachAssignment;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\RequestContext;

/**
 * Who may see and answer a Coach assignment: the assigned Coach themself, in the organization they act for.
 * A "no" on an existing assignment is reported as 404.
 */
final class AssignmentPolicy
{
    public function canRespond(CurrentUser $actor, RequestContext $context, CoachAssignment $assignment): bool
    {
        return $actor->role === Role::Coach
            && $assignment->coachUserId === $actor->id
            && $context->organizationId !== null
            && $context->organizationId->equals($assignment->organizationId);
    }
}
