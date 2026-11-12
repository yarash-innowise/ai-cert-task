<?php

declare(strict_types=1);

namespace App\Availability\Domain;

use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;

/** A Coach assigned to an event (Epic-02 owns events: `eventRef` is only a reference, with the date and window given at assignment). */
final readonly class CoachAssignment
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $eventRef,
        public DateTimeImmutable $eventDate,
        public TimeRange $window,
        public string $coachUserId,
        public string $coachName,
        public AssignmentStatus $status,
        public ?string $overrideId,
        public ?string $overrideReason,
        public ?string $changeNote,
        public DateTimeImmutable $createdAt,
    ) {}

    public function isOverride(): bool
    {
        return $this->overrideId !== null;
    }

    public function when(): string
    {
        return $this->eventDate->format('D Y-m-d') . ' ' . $this->window;
    }
}
