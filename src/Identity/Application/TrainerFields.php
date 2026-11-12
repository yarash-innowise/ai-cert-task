<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class TrainerFields implements RoleProfileFields
{
    public function __construct(public string $businessName, public ?string $details) {}

    public function section(): ProfileSection
    {
        return ProfileSection::Trainer;
    }
}
