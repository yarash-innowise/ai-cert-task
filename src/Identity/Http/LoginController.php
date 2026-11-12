<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\LoginUser;
use App\Identity\Application\SessionState;
use App\Identity\Domain\LoginFailure;
use App\Identity\Domain\LoginRefused;
use App\Identity\Domain\ReturnTo;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LoginController
{
    private const NOTICES = [
        'expired' => 'Your session has ended. Please sign in again.',
        'deactivated' => 'Account deactivated. Contact support.',
        'signed_out' => 'You have been signed out.',
        'reset' => 'Your password has been changed. Sign in with the new password.',
        'verified' => 'Your email address is confirmed. You can sign in.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly LoginUser $login,
        private readonly Redirect $redirect,
        private readonly ClientIp $clientIp,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $notice = $query['notice'] ?? null;

        return $this->view->render('auth/login.twig', [
            'page_title' => 'Sign in',
            'return_to' => ReturnTo::sanitize($query['return_to'] ?? null),
            'flash' => is_string($notice) ? (self::NOTICES[$notice] ?? null) : null,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $returnTo = ReturnTo::sanitize($body['return_to'] ?? null);

        $errors = [];
        if (trim($email) === '') {
            $errors['email'] = 'Enter your email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($errors !== []) {
            return $this->form($errors, $email, $returnTo, null, 422);
        }

        try {
            $user = $this->login->execute($email, $password, $this->clientIp->fromRequest($request));
        } catch (LoginRefused $e) {
            $status = match ($e->reason) {
                LoginFailure::InvalidCredentials => 401,
                LoginFailure::Locked => 429,
                LoginFailure::Deactivated, LoginFailure::Unverified => 403,
            };

            return $this->form([], $email, $returnTo, $e, $status);
        }

        $state = $request->getAttribute('session_state');
        if (!$state instanceof SessionState) {
            throw new \LogicException('SessionMiddleware is not in the pipeline.');
        }
        $state->start($user->id);

        return $this->redirect->to($returnTo ?? $user->role->dashboardPath());
    }

    /** @param array<string, string> $errors */
    private function form(array $errors, string $email, ?string $returnTo, ?LoginRefused $refusal, int $status): ResponseInterface
    {
        return $this->view->render('auth/login.twig', [
            'page_title' => 'Sign in',
            'return_to' => $returnTo,
            'old' => ['email' => $email],
            'errors' => $errors,
            'form_error' => $refusal?->getMessage(),
            'unverified' => $refusal?->reason === LoginFailure::Unverified,
        ], $status);
    }
}
