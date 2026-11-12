<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/** A Coach's profile (US-01.11): bio, credentials, certifications, and whether it is public to the Trainer's organization. */
final readonly class CoachProfile
{
    /** @param list<string> $certifications */
    public function __construct(
        public string $userId,
        public ?string $bio,
        public ?string $credentials,
        public array $certifications,
        public bool $isPublic,
    ) {}
}
