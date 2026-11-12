<?php

declare(strict_types=1);

namespace App\Identity\Domain\Policy;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;

/**
 * Who may reach an organization's data (AC-9, AC-10). Invoked by use cases, not controllers, so
 * console and worker paths are covered too. A "no" is reported to the user as 404 (not found),
 * never 403, so the existence of another organization is never confirmed.
 */
final class OrganizationPolicy
{
    /** Read access: the Super Admin everywhere, everyone else only in their active organization. */
    public function canAccess(CurrentUser $actor, RequestContext $context, OrganizationId $organization): bool
    {
        if ($actor->role === Role::SuperAdmin) {
            return true;
        }

        return $context->organizationId !== null && $context->organizationId->equals($organization);
    }

    /** Management: the Super Admin, or the Trainer whose active organization it is. */
    public function canManage(CurrentUser $actor, RequestContext $context, OrganizationId $organization): bool
    {
        return match ($actor->role) {
            Role::SuperAdmin => true,
            Role::Trainer => $context->organizationId !== null && $context->organizationId->equals($organization),
            Role::Coach, Role::Player => false,
        };
    }
}
