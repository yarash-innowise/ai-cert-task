<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\PlayerProfile;
use App\Shared\Domain\OrganizationId;

/** The players of one Trainer's organization: active associations only (ended ones are history). */
interface TrainerRoster
{
    /** @return list<PlayerProfile> */
    public function forOrganization(OrganizationId $organization): array;
}
