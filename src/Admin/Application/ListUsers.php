<?php

declare(strict_types=1);

namespace App\Admin\Application;

/** Users tool listing: 25 per page, newest first; a page past the end shows the last page. */
final class ListUsers
{
    public const PER_PAGE = 25;

    public function __construct(private readonly UserDirectoryQuery $directory) {}

    public function execute(UserDirectoryFilter $filter, int $page): UserPage
    {
        $page = max(1, $page);
        $result = $this->directory->search($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->directory->search($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        return new UserPage($result['items'], $result['total'], $page, self::PER_PAGE);
    }

    /** @return list<array{id: string, name: string}> */
    public function organizations(): array
    {
        return $this->directory->organizations();
    }
}
