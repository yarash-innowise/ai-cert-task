<?php

declare(strict_types=1);

namespace App\Admin\Application;

/**
 * Read side of the "Impersonation History" report (AC-56): built from the impersonation log and the audit log,
 * newest first, 25 per page; a page past the end shows the last page.
 */
interface ImpersonationHistoryQuery
{
    public const PER_PAGE = 25;

    /** @return array{items: list<ImpersonationHistoryRow>, total: int, page: int, lastPage: int} */
    public function page(int $page): array;
}
