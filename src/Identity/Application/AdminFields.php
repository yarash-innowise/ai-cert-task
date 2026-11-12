<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class AdminFields implements RoleProfileFields
{
    public function __construct(public ?string $adminSettings) {}

    public function section(): ProfileSection
    {
        return ProfileSection::Admin;
    }
}
