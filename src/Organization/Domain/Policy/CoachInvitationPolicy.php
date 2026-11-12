<?php

declare(strict_types=1);

namespace App\Organization\Domain\Policy;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;

/** Only the Trainer of an organization invites coaches for it and sees its coaches (AC-9, AC-10, AC-57). */
final class CoachInvitationPolicy
{
    public function canManage(CurrentUser $actor, RequestContext $context, OrganizationId $organization): bool
    {
        return $actor->role === Role::Trainer
            && $context->organizationId !== null
            && $context->organizationId->equals($organization);
    }
}
