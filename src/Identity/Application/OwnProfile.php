<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;
use DateTimeImmutable;

/** Everything the own-profile page shows: the editable values per section, the read-only facts, and the photo thumbnail. */
final readonly class OwnProfile
{
    /**
     * @param list<ProfileSection> $sections
     * @param array<string, string|bool> $values form field name => current value
     */
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public Role $role,
        public ?string $phone,
        public ?DateTimeImmutable $createdAt,
        public ?string $skillLevel,
        public bool $showSkillLevel,
        public array $sections,
        public array $values,
        public ?string $thumbnailUrl,
    ) {}

    /** @param string $section a {@see ProfileSection} value (called from the template) */
    public function has(string $section): bool
    {
        foreach ($this->sections as $candidate) {
            if ($candidate->value === $section) {
                return true;
            }
        }

        return false;
    }
}
