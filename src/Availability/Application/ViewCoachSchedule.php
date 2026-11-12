<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Identity\Domain\CurrentUser;
use App\Organization\Application\ListOrganizationCoaches;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/** A Trainer reads the schedule of one Coach of their own organization (404 for anyone else's Coach). */
final class ViewCoachSchedule
{
    public function __construct(
        private readonly ListOrganizationCoaches $coaches,
        private readonly AvailabilityRepository $availability,
    ) {}

    /** @throws NotFound the user is not a Coach of this Trainer's organization */
    public function execute(CurrentUser $actor, RequestContext $context, string $coachUserId): CoachScheduleView
    {
        $coach = $this->coaches->find($actor, $context, $coachUserId);

        return new CoachScheduleView($coach, $this->availability->week(AvailabilitySubject::Coach, $coach->userId));
    }
}
