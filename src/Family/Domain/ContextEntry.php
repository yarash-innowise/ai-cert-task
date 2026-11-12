<?php

declare(strict_types=1);

namespace App\Family\Domain;

/** One (player profile, Trainer) pair a signed-in user may act in. */
final readonly class ContextEntry
{
    public function __construct(
        public string $profileId,
        public ProfileKind $kind,
        public string $profileName,
        public string $organizationId,
        public string $businessName,
    ) {}
}
