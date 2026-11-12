<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Shared\Domain\OrganizationId;

/**
 * Port consumed by Epic-08 (camp flows): stores the data a person typed elsewhere so the
 * registration form can be pre-filled. The real implementation belongs to the Onboarding module
 * and is bound there; there is deliberately no stub.
 */
interface RegistrationPrefillWriter
{
    /**
     * @param array<string, string> $fields pre-fill values by form field name
     * @return string one-time token to put in the registration link
     */
    public function write(OrganizationId $organization, array $fields): string;
}
