<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\EditUser;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\User;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Super Admin edits any user (route gate: RequireRole(SuperAdmin)). */
final class EditUserController
{
    private const TEMPLATE = 'admin/users/edit.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly EditUser $editUser,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $user = $this->editUser->find((string) ($args['id'] ?? ''));

        return $this->render($user, $this->valuesOf($user), [], null, 200);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $request->getAttribute('current_user');
        if (!$actor instanceof CurrentUser) {
            throw new Forbidden();
        }
        $id = (string) ($args['id'] ?? '');
        $user = $this->editUser->find($id);
        $body = (array) $request->getParsedBody();

        try {
            $input = EditUserRequest::fromArray($body, self::TEMPLATE);
            $this->editUser->execute($id, $input, $actor->id, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($user, $e->old !== [] ? $e->old : $this->submitted($body), $e->errors, null, 422);
        } catch (Conflict $e) {
            // Show the current values of the record next to the refusal so the admin can redo the edit.
            $fresh = $this->editUser->find($id);

            return $this->render($fresh, $this->valuesOf($fresh), [], $e->getMessage(), 409);
        }

        return $this->redirect->to('/admin/users?notice=user_updated');
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function render(User $user, array $values, array $errors, ?string $formError, int $status): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, [
            'page_title' => 'Edit user',
            'subject' => $user,
            'old' => $values,
            'errors' => $errors,
            'form_error' => $formError,
        ], $status);
    }

    /** @return array<string, string> */
    private function valuesOf(User $user): array
    {
        return [
            'name' => $user->name,
            'phone' => $user->phone ?? '',
            'email' => $user->email->value,
            'version' => (string) $user->version,
        ];
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<string, string>
     */
    private function submitted(array $body): array
    {
        $out = [];
        foreach (['name', 'phone', 'email', 'version'] as $key) {
            $out[$key] = is_string($body[$key] ?? null) ? $body[$key] : '';
        }

        return $out;
    }
}
