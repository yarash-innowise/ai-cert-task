<?php

declare(strict_types=1);

namespace App\Family\Domain;

use DateTimeImmutable;

interface PlayerProfileRepository
{
    public function findById(string $id): ?PlayerProfile;

    /** The profile only when it belongs to the owner (otherwise null: callers answer 404). */
    public function findOwned(string $id, string $ownerUserId): ?PlayerProfile;

    /** The child profile a login belongs to (null when the user is not a child login). */
    public function findByLogin(string $loginUserId): ?PlayerProfile;

    public function findSelf(string $ownerUserId): ?PlayerProfile;

    /** @return list<PlayerProfile> the owner's children, oldest first */
    public function childrenOf(string $ownerUserId): array;

    public function createChild(
        string $ownerUserId,
        string $firstName,
        string $lastName,
        DateTimeImmutable $birthDate,
        Gender $gender,
        ?string $school,
        ?string $photoPath = null,
    ): PlayerProfile;

    /** @throws SelfProfileExists when the owner already has a "Me" profile */
    public function createSelf(string $ownerUserId, string $firstName, string $lastName, DateTimeImmutable $birthDate, ?Gender $gender): PlayerProfile;

    /** Sets the child's "Allow token spending without approval" setting (AC-42). */
    public function setTokenSpendWithoutApproval(string $profileId, bool $enabled): void;

    /** Sets the school and jersey number shown on a profile (a player's or a child's own details). */
    public function updateDetails(string $profileId, ?string $school, ?int $jerseyNumber): void;

    /** Changes a child's name together with the details (the parent's edit of a child). */
    public function updateChild(string $profileId, string $firstName, string $lastName, ?string $school, ?int $jerseyNumber): void;

    /**
     * Points the profile at a new photo file and returns the previous file name (null when there was none).
     * Locks the row, so call it inside the transaction that also queues the old file's deletion.
     */
    public function swapPhoto(string $profileId, ?string $photoPath): ?string;

    /** @return bool false when the profile already has a login (one login per child) */
    public function attachLogin(string $profileId, string $loginUserId): bool;
}
