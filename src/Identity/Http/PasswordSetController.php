<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\SessionState;
use App\Identity\Application\SetRequiredPassword;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** `/password/set`: the only page a user flagged must_set_password may use (AuthenticationMiddleware). */
final class PasswordSetController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly SetRequiredPassword $setPassword,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser || !$user->mustSetPassword) {
            return $this->redirect->to('/');
        }

        return $this->render([], 200);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser || !$user->mustSetPassword) {
            return $this->redirect->to('/');
        }
        $body = (array) $request->getParsedBody();
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirmation = is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '';

        try {
            $this->setPassword->execute($user->id, $password, $confirmation, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($e->errors, 422);
        }

        $state = $request->getAttribute('session_state');
        if ($state instanceof SessionState) {
            $state->regenerate();
        }

        return $this->redirect->to($user->role->dashboardPath());
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status): ResponseInterface
    {
        return $this->view->render('auth/password-set.twig', ['page_title' => 'Choose your password', 'errors' => $errors], $status);
    }
}
