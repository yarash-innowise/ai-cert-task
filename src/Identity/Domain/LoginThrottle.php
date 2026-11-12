<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

/**
 * Failure throttle. The check and the recording happen as one atomic step per email and per IP, so a
 * parallel burst cannot get more than the allowed number of attempts through (AC-3): an allowed
 * attempt is recorded as a failure up front, and is settled afterwards when the password was right.
 */
interface LoginThrottle
{
    /**
     * Refuses (a lock end) when the email or the IP is locked, otherwise records a pending failure.
     * A refused attempt records nothing.
     */
    public function reserveAttempt(string $email, string $ip, DateTimeImmutable $now): LoginReservation;

    /** The password was right and sign-in succeeded: the reserved attempt no longer counts as a failure. */
    public function recordSuccess(LoginReservation $reservation): void;

    /** The password was right but the account cannot sign in (inactive, unverified): not a failed guess. */
    public function release(LoginReservation $reservation): void;
}
