<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Email;
use DateTimeImmutable;

interface UserRepository
{
    public function findById(string $id): ?User;

    public function findByEmail(Email $email): ?User;

    /** @throws EmailAlreadyRegistered when the (case-insensitive) email exists */
    public function create(
        Email $email,
        string $name,
        Role $role,
        ?string $passwordHash,
        ?DateTimeImmutable $emailVerifiedAt,
        bool $mustSetPassword = false,
        ?string $phone = null,
        string $accountKind = 'standard',
        ?string $guardianUserId = null,
    ): User;

    /** Sets a new hash and clears must_set_password. */
    public function updatePasswordHash(string $userId, string $hash): void;

    /**
     * Optimistic update of the editable account fields: succeeds only when the row still has
     * $expectedVersion (then bumps it). Role and status are never touched here.
     *
     * @return bool false when the record changed since it was read (or does not exist)
     * @throws EmailAlreadyRegistered when another account already uses the (case-insensitive) email
     */
    public function updateAccount(string $userId, string $name, ?string $phone, Email $email, int $expectedVersion): bool;

    /**
     * The account holder's own edit of name and phone. Bumps the version, so an administrator's form that was
     * opened before this save is refused as out of date. Email, role and status are never touched here.
     */
    public function updateProfile(string $userId, string $name, ?string $phone): void;

    public function markEmailVerified(string $userId, DateTimeImmutable $at): void;
}
