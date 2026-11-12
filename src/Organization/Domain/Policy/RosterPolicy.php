<?php

declare(strict_types=1);

namespace App\Organization\Domain\Policy;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;

/** A Trainer sees and manages only the people of their own organization (AC-9, AC-10). */
final class RosterPolicy
{
    public function canView(CurrentUser $actor, RequestContext $context, OrganizationId $organization): bool
    {
        return $actor->role === Role::Trainer
            && $context->organizationId !== null
            && $context->organizationId->equals($organization);
    }
}
