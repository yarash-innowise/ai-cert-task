<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;

/**
 * What the deactivate, reactivate and delete use cases need from the database. Every method runs inside the
 * caller's transaction; {@see self::lock()} must be called first so concurrent lifecycle changes queue up.
 */
interface UserLifecycleStore
{
    /** Reads the user and holds a row lock until the transaction ends (SELECT ... FOR UPDATE). */
    public function lock(string $userId): ?User;

    /** Locks every active Super Admin row and returns how many there are (the last-admin check, race-free). */
    public function lockActiveSuperAdmins(): int;

    public function setStatus(string $userId, UserStatus $status): void;

    /**
     * Moves the guardian's child logins from one status to the other.
     *
     * @return list<string> the ids of the child logins that moved
     */
    public function moveChildLogins(string $guardianUserId, UserStatus $from, UserStatus $to): array;

    /** The status of the child login's guardian, or null when the user is not a child login. */
    public function guardianStatusOf(string $childUserId): ?UserStatus;

    /** Sets the status ('active' or 'inactive') of the Trainer's organization; returns its id (null: no organization). */
    public function setOrganizationStatus(string $trainerUserId, string $status): ?string;

    /**
     * Locks the Trainer's organization row FOR UPDATE (so a concurrent invitation acceptance, which takes it FOR SHARE,
     * either finishes before the active-coach count or sees the organization gone). No-op without an organization.
     */
    public function lockOrganizationOf(string $trainerUserId): void;

    /** Ends every active coach membership of the Trainer's organization (status 'ended', ended_at); returns how many. */
    public function endCoachMemberships(string $trainerUserId): int;

    /** Coaches that are active members of the Trainer's organization and whose account is active. */
    public function activeCoachCount(string $trainerUserId): int;

    /**
     * Removes the personal data of the user (and of the player profiles that are theirs; for a guardian also the
     * child logins) and marks the accounts deleted, keeping every row so history stays intact. Other tables lose
     * what names the person: invitations, queued emails, prefills, notifications, free-text notes, login attempts. Returns what is left to clean up once the transaction commits.
     */
    public function anonymize(string $userId): AnonymizationResult;
}
