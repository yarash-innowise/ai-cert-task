<?php

declare(strict_types=1);

namespace App\Approval\Domain\Policy;

use App\Approval\Domain\ApprovalRequest;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;

/**
 * Who may do what with a request (AC-9, AC-45): a child login asks and watches its own requests; only
 * the child's own parent (never a child login, never another parent) sees and decides them. A "no" on
 * an existing request is reported as 404 so it does not confirm that the request exists.
 */
final class ApprovalRequestPolicy
{
    /** Only a child login starts a request (a parent, or a parent without children, never does). */
    public function canRequest(CurrentUser $actor): bool
    {
        return $actor->role === Role::Player && $actor->isChildLogin();
    }

    public function canViewAsChild(CurrentUser $actor, ApprovalRequest $request): bool
    {
        return $this->canRequest($actor) && $request->childUserId === $actor->id;
    }

    public function canViewAsParent(CurrentUser $actor, ApprovalRequest $request): bool
    {
        return $actor->role === Role::Player && !$actor->isChildLogin() && $request->parentUserId === $actor->id;
    }

    /** Only the child's own parent decides. */
    public function canDecide(CurrentUser $actor, ApprovalRequest $request): bool
    {
        return $this->canViewAsParent($actor, $request);
    }
}
