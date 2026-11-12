<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\WeeklyAvailability;
use App\Organization\Application\RosterCoach;

final readonly class CoachScheduleView
{
    public function __construct(public RosterCoach $coach, public WeeklyAvailability $week) {}
}
