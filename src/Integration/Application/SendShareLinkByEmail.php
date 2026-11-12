<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Shared\Domain\OrganizationId;

/**
 * Port consumed by Epic-08: emails the organization's ShareLink to someone. The real implementation
 * belongs to the Onboarding module and is bound there; there is deliberately no stub.
 */
interface SendShareLinkByEmail
{
    public function send(OrganizationId $organization, string $recipientEmail, string $recipientName = ''): void;
}
