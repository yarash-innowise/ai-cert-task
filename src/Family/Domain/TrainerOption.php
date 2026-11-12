<?php

declare(strict_types=1);

namespace App\Family\Domain;

/** A Trainer a parent may associate a profile with. */
final readonly class TrainerOption
{
    public function __construct(public string $organizationId, public string $businessName) {}
}
