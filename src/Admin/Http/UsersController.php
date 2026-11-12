<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\ListUsers;
use App\Admin\Application\UserDirectoryFilter;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserStatus;
use App\Shared\Domain\Uuid;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Super Admin Users tool: the list with search and filters that live in this tool only. */
final class UsersController
{
    private const NOTICES = [
        'trainer_created' => 'Trainer created. A setup link was emailed to them.',
        'user_updated' => 'User saved.',
        'user_deactivated' => 'User deactivated. Their sessions ended.',
        'user_reactivated' => 'User reactivated. They can sign in again.',
        'user_deleted' => 'User deleted. Their personal data was removed.',
        'impersonation_ended' => 'You are back in your own view.',
        'impersonation_timeout' => 'The impersonation ended after 1 hour. You are back in your own view.',
        'impersonation_browser_closed' => 'The impersonation ended because the browser was closed. You are back in your own view.',
        'impersonation_target_deleted' => 'The impersonation ended because the user was deleted. You are back in your own view.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly ListUsers $listUsers,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '';
        $role = is_string($query['role'] ?? null) ? Role::tryFrom($query['role']) : null;
        $status = is_string($query['status'] ?? null) ? UserStatus::tryFrom($query['status']) : null;
        $organization = is_string($query['organization'] ?? null) ? Uuid::tryFromString($query['organization'])?->value : null;
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $result = $this->listUsers->execute(new UserDirectoryFilter($search, $role, $status, $organization), $page);

        $notice = $query['notice'] ?? null;

        return $this->view->render('admin/users/index.twig', [
            'page_title' => 'Users',
            'page' => $result,
            'organizations' => $this->listUsers->organizations(),
            'roles' => Role::cases(),
            'statuses' => UserStatus::cases(),
            'filters' => [
                'q' => $search,
                'role' => $role === null ? '' : $role->value,
                'status' => $status === null ? '' : $status->value,
                'organization' => $organization ?? '',
            ],
            'flash' => is_string($notice) ? (self::NOTICES[$notice] ?? null) : null,
        ]);
    }
}
