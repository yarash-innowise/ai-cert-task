<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\PlayerProfile;
use App\Family\Domain\TrainerAssociation;

final readonly class ChildListItem
{
    /** @param list<TrainerAssociation> $associations */
    public function __construct(
        public PlayerProfile $profile,
        /** Null when the birth date is unknown (anonymized player). */
        public ?int $age,
        public array $associations,
        public bool $hasLogin,
    ) {}
}
