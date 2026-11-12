<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The authenticated principal of the current request (never carries the password hash).
 */
final readonly class CurrentUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public Role $role,
        public string $accountKind,
        public bool $mustSetPassword,
        public bool $emailVerified,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            $user->id,
            $user->name,
            $user->email->value,
            $user->role,
            $user->accountKind,
            $user->mustSetPassword,
            $user->emailVerifiedAt !== null,
        );
    }

    public function isChildLogin(): bool
    {
        return $this->accountKind === 'child';
    }
}
