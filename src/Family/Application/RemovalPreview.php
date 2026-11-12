<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\PlayerProfile;
use App\Family\Domain\TrainerAssociation;

final readonly class RemovalPreview
{
    public function __construct(public PlayerProfile $profile, public TrainerAssociation $association) {}
}
