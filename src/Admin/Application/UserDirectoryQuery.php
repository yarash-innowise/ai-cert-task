<?php

declare(strict_types=1);

namespace App\Admin\Application;

/** Read side of the Users tool (implemented over PDO in Admin/Infrastructure). */
interface UserDirectoryQuery
{
    /** @return array{items: list<UserListItem>, total: int} the rows of one page and the total matching the filter */
    public function search(UserDirectoryFilter $filter, int $limit, int $offset): array;

    /** @return list<array{id: string, name: string}> organizations for the filter drop-down */
    public function organizations(): array;
}
