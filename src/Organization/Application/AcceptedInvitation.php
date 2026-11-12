<?php

declare(strict_types=1);

namespace App\Organization\Application;

final readonly class AcceptedInvitation
{
    public function __construct(public string $coachUserId, public string $businessName, public bool $registered) {}
}
