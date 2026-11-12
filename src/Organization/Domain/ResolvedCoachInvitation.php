<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/** An invitation found by its secret token, with the Trainer's business name and whether the Trainer can still take coaches. */
final readonly class ResolvedCoachInvitation
{
    public function __construct(
        public CoachInvitation $invitation,
        public string $businessName,
        public bool $organizationActive,
    ) {}
}
