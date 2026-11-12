<?php

declare(strict_types=1);

namespace App\Organization\Domain;

interface OrganizationRepository
{
    public function create(string $ownerUserId, string $businessName): Organization;

    /** The Trainer's own edit of the business name and organization details. */
    public function updateDetails(string $organizationId, string $businessName, ?string $details): void;

    /** @return string|null the organization details text */
    public function detailsOf(string $organizationId): ?string;

    public function findByOwner(string $ownerUserId): ?Organization;

    public function findById(string $id): ?Organization;
}
