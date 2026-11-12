<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/** Per-account profile data that does not belong on the sign-in record: photo, a parent's emergency contact, the Super Admin's settings. */
final readonly class UserExtras
{
    public function __construct(
        public ?string $photoPath = null,
        public ?string $emergencyContact = null,
        public ?string $adminSettings = null,
    ) {}
}
