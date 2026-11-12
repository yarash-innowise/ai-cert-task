<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Policy;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;

/** A ShareLink belongs to exactly one Trainer: only that Trainer manages it (AC-9, AC-18). */
final class ShareLinkPolicy
{
    public function canManage(CurrentUser $actor, RequestContext $context, OrganizationId $organization): bool
    {
        return $actor->role === Role::Trainer
            && $context->organizationId !== null
            && $context->organizationId->equals($organization);
    }
}
