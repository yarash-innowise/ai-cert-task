<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface UserProfileRepository
{
    public function find(string $userId): UserExtras;

    /** Saves the text extras (creating the row on first use); the photo is left alone. */
    public function saveText(string $userId, ?string $emergencyContact, ?string $adminSettings): void;

    /**
     * Points the account at a new photo file and returns the previous file name (null when there was none).
     * Locks the row, so call it inside the transaction that also queues the old file's deletion.
     */
    public function swapPhoto(string $userId, ?string $photoPath): ?string;
}
