<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class PlayerFields implements RoleProfileFields
{
    public function __construct(public ?string $school, public ?int $jerseyNumber) {}

    public function section(): ProfileSection
    {
        return ProfileSection::Player;
    }
}
