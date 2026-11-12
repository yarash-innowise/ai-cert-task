<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\UserStatus;
use DateTimeImmutable;

/** One row of the Users tool. */
final readonly class UserListItem
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public Role $role,
        public UserStatus $status,
        public ?string $organizationName,
        public DateTimeImmutable $createdAt,
    ) {}
}
