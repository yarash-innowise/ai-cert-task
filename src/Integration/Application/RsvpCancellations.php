<?php

declare(strict_types=1);

namespace App\Integration\Application;

/**
 * Port to event registration (Epic-02): cancelling ONE RSVP after the parent approved the child's
 * cancellation request (AC-48). Epic-01 binding: NoopRsvpCancellations.
 */
interface RsvpCancellations
{
    /** @return bool true only when the registration really is cancelled afterwards */
    public function cancel(string $eventRef, string $playerProfileId): bool;
}
