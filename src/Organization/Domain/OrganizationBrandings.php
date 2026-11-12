<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Shared\Domain\HexColor;
use App\Shared\Domain\OrganizationId;

/** Reads and writes the branding of one organization (a tenant repository: every method takes the OrganizationId). */
interface OrganizationBrandings
{
    /** @return Branding|null null when the organization does not exist */
    public function find(OrganizationId $organization): ?Branding;

    /** Like {@see self::find()} but locks the row, so a concurrent change waits (use inside a transaction). */
    public function lock(OrganizationId $organization): ?Branding;

    public function save(OrganizationId $organization, ?string $logoPath, ?HexColor $color): void;
}
