<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\WeeklyAvailability;
use App\Family\Domain\PlayerProfile;

/** One player profile with its saved week, for the edit form. */
final readonly class PlayerAvailabilityView
{
    public function __construct(public PlayerProfile $profile, public WeeklyAvailability $week) {}
}
