<?php

declare(strict_types=1);

namespace App\Organization\Domain;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $ownerUserId,
        public string $businessName,
        public OrganizationStatus $status,
        public string $timezone,
    ) {}
}
