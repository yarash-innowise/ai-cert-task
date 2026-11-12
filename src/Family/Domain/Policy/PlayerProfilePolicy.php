<?php

declare(strict_types=1);

namespace App\Family\Domain\Policy;

use App\Family\Domain\PlayerProfile;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;

/**
 * Who may act on a player profile (AC-9): the owning parent / player account only, and never a
 * child login (a child cannot manage family data). A "no" on an existing profile is reported as 404.
 */
final class PlayerProfilePolicy
{
    /** Creating children, adding a "Me" profile: Player/Parent accounts that are not child logins. */
    public function canManageFamily(CurrentUser $actor): bool
    {
        return $actor->role === Role::Player && !$actor->isChildLogin();
    }

    public function canManage(CurrentUser $actor, PlayerProfile $profile): bool
    {
        return $this->canManageFamily($actor) && $profile->ownerUserId === $actor->id;
    }

    /** Only the owning parent sets up a login, and only for a child profile that has none yet (G1). */
    public function canEnableChildLogin(CurrentUser $actor, PlayerProfile $profile): bool
    {
        return $this->canManage($actor, $profile) && $profile->isChild();
    }
}
