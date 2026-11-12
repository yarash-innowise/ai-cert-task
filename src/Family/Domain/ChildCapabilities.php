<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\Exception\Forbidden;

/**
 * What a child login may do (AC-46) and may not (AC-47). The route guard (DenyChildLoginMiddleware)
 * and the use cases both consult this one list, so a forbidden action is refused even if a route
 * guard is forgotten. Anything not listed as allowed is refused.
 */
final class ChildCapabilities
{
    /** @var list<ChildAction> */
    public const ALLOWED = [
        ChildAction::BrowseEvents,
        ChildAction::ViewContent,
        ChildAction::ViewProgress,
        ChildAction::SubmitFeedbackRequest,
        ChildAction::UpdateBasicProfile,
        ChildAction::ViewTokens,
        ChildAction::SwitchOwnContext,
    ];

    public static function allows(ChildAction $action): bool
    {
        return in_array($action, self::ALLOWED, true);
    }

    /** @throws Forbidden a child login attempting an action outside its allowed list */
    public static function assertAllowed(CurrentUser $actor, ChildAction $action): void
    {
        if ($actor->isChildLogin() && !self::allows($action)) {
            throw new Forbidden();
        }
    }

    /** Opening a Trainer's link to join is for adult players and parents only; a child's attempt is blocked (AC-49). */
    public static function assertMayUseLinks(CurrentUser $actor): void
    {
        if ($actor->role !== Role::Player) {
            throw new Forbidden();
        }
        self::assertAllowed($actor, ChildAction::AddTrainer);
    }
}
