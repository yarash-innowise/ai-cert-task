<?php

declare(strict_types=1);

namespace App\Identity\Domain\Policy;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;

/**
 * Who may view or manage another user (AC-9, AC-10). The caller passes the organizations the target
 * user belongs to; a Trainer reaches only users of their own active organization.
 */
final class UserPolicy
{
    /** @param list<OrganizationId> $targetOrganizations organizations the target user belongs to */
    public function canView(CurrentUser $actor, RequestContext $context, string $targetUserId, array $targetOrganizations): bool
    {
        if ($actor->id === $targetUserId || $actor->role === Role::SuperAdmin) {
            return true;
        }

        return $this->sharesActiveOrganization($actor, $context, $targetOrganizations);
    }

    /** @param list<OrganizationId> $targetOrganizations */
    public function canManage(CurrentUser $actor, RequestContext $context, string $targetUserId, array $targetOrganizations): bool
    {
        return match ($actor->role) {
            Role::SuperAdmin => true,
            Role::Trainer => $actor->id !== $targetUserId && $this->sharesActiveOrganization($actor, $context, $targetOrganizations),
            Role::Coach, Role::Player => false,
        };
    }

    /** Only the Super Admin edits accounts (email, name, phone) of other people (Q-01.11). */
    public function canEditAccount(CurrentUser $actor): bool
    {
        return $actor->role === Role::SuperAdmin;
    }

    /** @param list<OrganizationId> $targetOrganizations */
    private function sharesActiveOrganization(CurrentUser $actor, RequestContext $context, array $targetOrganizations): bool
    {
        if ($context->organizationId === null) {
            return false;
        }
        foreach ($targetOrganizations as $organization) {
            if ($organization->equals($context->organizationId)) {
                return true;
            }
        }

        return false;
    }
}
