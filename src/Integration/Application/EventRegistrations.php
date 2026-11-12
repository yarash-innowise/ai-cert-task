<?php

declare(strict_types=1);

namespace App\Integration\Application;

/**
 * Port to event registration (Epic-02). Epic-01 binding: NoopEventRegistrations, which logs the
 * call and reports that nothing was registered or cancelled.
 */
interface EventRegistrations
{
    /** @return bool true only when the registration really exists afterwards */
    public function register(string $eventRef, string $playerProfileId): bool;

    /**
     * Cancels the profile's upcoming registrations (child removed or deactivated); with an
     * organization, only those of that Trainer (a child removed from one Trainer keeps the others).
     *
     * @return int how many were cancelled
     */
    public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int;
}
