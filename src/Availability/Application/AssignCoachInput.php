<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\TimeRange;
use DateTimeImmutable;

/**
 * A Trainer's request to assign a Coach to an event. Events are Epic-02's, so the event is the reference
 * plus its date and wall-clock window (in the organization's time zone). `continueAnyway` and `reason` come
 * from the second step, after the Trainer saw the schedule warning.
 */
final readonly class AssignCoachInput
{
    public function __construct(
        public string $eventRef,
        public DateTimeImmutable $eventDate,
        public TimeRange $window,
        public string $coachUserId,
        public bool $continueAnyway = false,
        public ?string $reason = null,
    ) {}
}
