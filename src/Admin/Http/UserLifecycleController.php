<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\DeactivateUser;
use App\Admin\Application\DeleteUser;
use App\Admin\Application\EditUser;
use App\Admin\Application\ReactivateUser;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\User;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Super Admin deactivates, reactivates and deletes users (route gate: RequireRole(SuperAdmin)). Deactivation and
 * deletion go through a confirmation page first; a refusal (self, last Super Admin, Trainer with coaches ...) is
 * shown on that page with a message.
 */
final class UserLifecycleController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly EditUser $users,
        private readonly DeactivateUser $deactivate,
        private readonly ReactivateUser $reactivate,
        private readonly DeleteUser $delete,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function deactivateForm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        return $this->confirm('admin/users/deactivate-confirm.twig', 'Deactivate user', $this->users->find((string) ($args['id'] ?? '')), [], 200);
    }

    /** @param array<string, string> $args */
    public function deactivate(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $user = $this->users->find((string) ($args['id'] ?? ''));
        try {
            $this->deactivate->execute($user->id, $this->actor($request)->id, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->confirm('admin/users/deactivate-confirm.twig', 'Deactivate user', $user, $e->errors, 422);
        }

        return $this->redirect->to('/admin/users?notice=user_deactivated');
    }

    /** @param array<string, string> $args */
    public function reactivate(ServerRequestInterface $request, array $args): ResponseInterface
    {
        // No confirmation page: reactivating restores access and is undone by deactivating again. A refusal shows the generic form-error page.
        $this->reactivate->execute((string) ($args['id'] ?? ''), $this->actor($request)->id, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/admin/users?notice=user_reactivated');
    }

    /** @param array<string, string> $args */
    public function deleteForm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        return $this->confirm('admin/users/delete-confirm.twig', 'Delete user', $this->users->find((string) ($args['id'] ?? '')), [], 200);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $user = $this->users->find((string) ($args['id'] ?? ''));
        $body = (array) $request->getParsedBody();
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        try {
            $this->delete->execute($user->id, $reason, $this->actor($request)->id, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->confirm('admin/users/delete-confirm.twig', 'Delete user', $user, $e->errors, 422, $reason);
        }

        return $this->redirect->to('/admin/users?notice=user_deleted');
    }

    /** @param array<string, string> $errors */
    private function confirm(string $template, string $title, User $user, array $errors, int $status, string $reason = ''): ResponseInterface
    {
        return $this->view->render($template, [
            'page_title' => $title,
            'subject' => $user,
            'errors' => $errors,
            'form_error' => $errors['user'] ?? null,
            'old' => ['reason' => $reason],
        ], $status);
    }

    private function actor(ServerRequestInterface $request): CurrentUser
    {
        $actor = $request->getAttribute('current_user');

        return $actor instanceof CurrentUser ? $actor : throw new Forbidden();
    }
}
