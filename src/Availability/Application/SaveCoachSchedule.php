<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\WeeklyAvailability;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Http\Exception\Forbidden;

/**
 * A Coach's own recurring weekly schedule ("My Times", AC-64): several time slots per day. It is always the
 * signed-in Coach's own week; there is no way to name someone else's. Changing it later never touches an
 * assignment that already exists.
 */
final class SaveCoachSchedule
{
    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly SaveAvailability $save,
    ) {}

    /** @throws Forbidden not a Coach */
    public function view(CurrentUser $actor): WeeklyAvailability
    {
        $this->assertCoach($actor);

        return $this->availability->week(AvailabilitySubject::Coach, $actor->id);
    }

    /**
     * @throws Forbidden not a Coach
     * @throws \App\Shared\Http\Exception\ValidationFailed overlapping ranges
     */
    public function execute(CurrentUser $actor, WeeklyAvailability $week): void
    {
        $this->assertCoach($actor);
        $this->save->execute(AvailabilitySubject::Coach, $actor->id, $week);
    }

    private function assertCoach(CurrentUser $actor): void
    {
        if ($actor->role !== Role::Coach) {
            throw new Forbidden();
        }
    }
}
