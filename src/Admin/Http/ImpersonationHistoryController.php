<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\ImpersonationHistoryQuery;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Super Admin "Impersonation History" report (AC-56); route gate: RequireRole(SuperAdmin). */
final class ImpersonationHistoryController
{
    public function __construct(
        private readonly View $view,
        private readonly ImpersonationHistoryQuery $history,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $page = $request->getQueryParams()['page'] ?? null;
        $result = $this->history->page(is_string($page) && ctype_digit($page) ? (int) $page : 1);

        return $this->view->render('admin/impersonations.twig', [
            'page_title' => 'Impersonation history',
            'page' => $result,
        ]);
    }
}
