<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Shared\Domain\Email;
use DateTimeImmutable;
use PDO;
use PDOException;

final class PdoUserRepository implements UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(string $id): ?User
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            return null;
        }

        return $this->fetch('SELECT * FROM users WHERE id = :v', $id);
    }

    public function findByEmail(Email $email): ?User
    {
        return $this->fetch('SELECT * FROM users WHERE email = :v', $email->value);
    }

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
    ): User {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, name, role, password_hash, email_verified_at, must_set_password, phone, account_kind, guardian_user_id)
                 VALUES (:email, :name, :role, :hash, :verified, :must, :phone, :kind, :guardian) RETURNING *',
            );
            $stmt->bindValue('email', $email->value);
            $stmt->bindValue('name', $name);
            $stmt->bindValue('role', $role->value);
            $stmt->bindValue('hash', $passwordHash);
            $stmt->bindValue('verified', $emailVerifiedAt?->format('Y-m-d H:i:s.uP'));
            $stmt->bindValue('must', $mustSetPassword, PDO::PARAM_BOOL);
            $stmt->bindValue('phone', $phone);
            $stmt->bindValue('kind', $accountKind);
            $stmt->bindValue('guardian', $guardianUserId);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new EmailAlreadyRegistered($e);
            }
            throw $e;
        }

        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    public function updatePasswordHash(string $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :h, must_set_password = false, version = version + 1, updated_at = now() WHERE id = :id',
        );
        $stmt->execute(['h' => $hash, 'id' => $userId]);
    }

    public function updateAccount(string $userId, string $name, ?string $phone, Email $email, int $expectedVersion): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE users SET name = :name, phone = :phone, email = :email, version = version + 1, updated_at = now()
                 WHERE id = :id AND version = :v',
            );
            $stmt->bindValue('name', $name);
            $stmt->bindValue('phone', $phone);
            $stmt->bindValue('email', $email->value);
            $stmt->bindValue('id', $userId);
            $stmt->bindValue('v', $expectedVersion, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new EmailAlreadyRegistered($e);
            }
            throw $e;
        }

        return $stmt->rowCount() === 1;
    }

    public function updateProfile(string $userId, string $name, ?string $phone): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET name = :name, phone = :phone, version = version + 1, updated_at = now() WHERE id = :id');
        $stmt->bindValue('name', $name);
        $stmt->bindValue('phone', $phone);
        $stmt->bindValue('id', $userId);
        $stmt->execute();
    }

    public function markEmailVerified(string $userId, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, :at), version = version + 1, updated_at = now() WHERE id = :id',
        );
        $stmt->execute(['at' => $at->format('Y-m-d H:i:s.uP'), 'id' => $userId]);
    }

    private function fetch(string $sql, string $value): ?User
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (string) $row['id'],
            Email::fromString((string) $row['email']),
            is_string($row['password_hash']) ? $row['password_hash'] : null,
            (string) $row['name'],
            Role::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            (string) $row['account_kind'],
            (bool) $row['must_set_password'],
            is_string($row['email_verified_at']) ? new DateTimeImmutable($row['email_verified_at']) : null,
            (int) $row['version'],
            is_string($row['phone']) ? $row['phone'] : null,
            is_string($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
        );
    }
}
