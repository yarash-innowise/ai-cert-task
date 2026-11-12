<?php

declare(strict_types=1);

namespace App\Admin\Application;

/** One page of the Users tool plus the counts the pager needs. */
final readonly class UserPage
{
    /** @param list<UserListItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
