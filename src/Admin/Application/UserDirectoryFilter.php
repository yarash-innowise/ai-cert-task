<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\UserStatus;

/** Search text and filters of the Users tool (local to that tool). */
final readonly class UserDirectoryFilter
{
    public function __construct(
        public string $search = '',
        public ?Role $role = null,
        public ?UserStatus $status = null,
        public ?string $organizationId = null,
    ) {}
}
