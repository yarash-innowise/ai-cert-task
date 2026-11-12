<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\ProfileKind;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\RequestContext;

/**
 * The context selector's content (AC-36, AC-37). A parent sees "Your Training" (Me with each
 * Trainer) and "Your Children's Training" (each child with each Trainer); a parent who does not
 * train only sees the children section. A child login sees only its own Trainers, never "Me" or
 * the parent's data. Trainers and Coaches have one fixed organization and no selector.
 */
final class ListContexts
{
    public const YOUR_TRAINING = 'Your Training';
    public const CHILDREN_TRAINING = "Your Children's Training";
    public const CHILD_OWN = 'Your Trainers';

    public function __construct(private readonly TrainerAssociations $associations) {}

    public function execute(?CurrentUser $user, RequestContext $active): ContextMenu
    {
        if ($user === null || $user->role !== Role::Player) {
            return ContextMenu::empty();
        }

        $own = [];
        $children = [];
        $current = null;
        foreach ($this->associations->contextsOf($user->id) as $entry) {
            $isActive = $active->profileId === $entry->profileId
                && $active->organizationId?->value === $entry->organizationId;
            $isMe = $entry->kind === ProfileKind::Me || $user->isChildLogin();
            $item = new ContextItem(
                $entry->organizationId,
                $entry->profileId,
                $entry->profileName,
                $entry->businessName,
                $isMe ? $entry->businessName : $entry->profileName . ' with ' . $entry->businessName,
                $isActive,
            );
            $current = $isActive ? $item : $current;
            if ($isMe) {
                $own[] = $item;
            } else {
                $children[] = $item;
            }
        }

        $groups = [];
        if ($own !== []) {
            $groups[] = new ContextGroup($user->isChildLogin() ? self::CHILD_OWN : self::YOUR_TRAINING, $own);
        }
        if ($children !== []) {
            $groups[] = new ContextGroup(self::CHILDREN_TRAINING, $children);
        }

        return new ContextMenu($groups, $current);
    }
}
