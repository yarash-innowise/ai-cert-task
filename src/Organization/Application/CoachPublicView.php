<?php

declare(strict_types=1);

namespace App\Organization\Application;

/** A Coach's profile as shown on the public profile page (AC-68). */
final readonly class CoachPublicView
{
    /** @param list<string> $certifications */
    public function __construct(
        public string $coachId,
        public string $name,
        public ?string $bio,
        public ?string $credentials,
        public array $certifications,
        public bool $isPublic,
        public bool $hasPhoto,
        public ?string $organizationName,
        public bool $viewerIsOwner,
    ) {}
}
