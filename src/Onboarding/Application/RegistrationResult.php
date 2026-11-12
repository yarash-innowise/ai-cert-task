<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

final readonly class RegistrationResult
{
    public function __construct(public string $userId, public string $profileId, public string $businessName) {}
}
