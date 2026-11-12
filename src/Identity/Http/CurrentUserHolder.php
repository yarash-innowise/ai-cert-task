<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Domain\CurrentUser;

/**
 * Lets the layout (Twig `current_user()`) see the authenticated user of this request.
 */
final class CurrentUserHolder
{
    private ?CurrentUser $user = null;

    public function set(?CurrentUser $user): void
    {
        $this->user = $user;
    }

    public function get(): ?CurrentUser
    {
        return $this->user;
    }
}
