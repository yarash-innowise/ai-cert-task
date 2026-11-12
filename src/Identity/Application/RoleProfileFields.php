<?php

declare(strict_types=1);

namespace App\Identity\Application;

/** The editable role-specific fields of one {@see ProfileSection}. Email, role, skill level and creation date are in none of them. */
interface RoleProfileFields
{
    public function section(): ProfileSection;
}
