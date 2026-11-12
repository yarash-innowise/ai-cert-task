<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class CoachFields implements RoleProfileFields
{
    /** @param list<string> $certifications */
    public function __construct(
        public ?string $bio,
        public ?string $credentials,
        public array $certifications,
        public bool $isPublic,
    ) {}

    public function section(): ProfileSection
    {
        return ProfileSection::Coach;
    }
}
