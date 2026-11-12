<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Email;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public string $id,
        public Email $email,
        public ?string $passwordHash,
        public string $name,
        public Role $role,
        public UserStatus $status,
        public string $accountKind,
        public bool $mustSetPassword,
        public ?DateTimeImmutable $emailVerifiedAt,
        public int $version,
        public ?string $phone = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Self-registered accounts must confirm their email before signing in (Q-01.05). */
    public function needsEmailVerification(): bool
    {
        return $this->emailVerifiedAt === null && !$this->mustSetPassword;
    }
}
