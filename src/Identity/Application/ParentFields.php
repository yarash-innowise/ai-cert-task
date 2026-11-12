<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class ParentFields implements RoleProfileFields
{
    public function __construct(public ?string $emergencyContact) {}

    public function section(): ProfileSection
    {
        return ProfileSection::Parent;
    }
}
