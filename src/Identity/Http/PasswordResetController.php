<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\CompletePasswordReset;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\InvalidToken;
use App\Shared\Domain\Clock;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly RequestPasswordReset $request,
        private readonly CompletePasswordReset $complete,
        private readonly AuthTokenRepository $tokens,
        private readonly Clock $clock,
        private readonly ClientIp $clientIp,
    ) {}

    public function forgotForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('auth/forgot.twig', ['page_title' => 'Reset your password', 'sent' => false, 'old' => [], 'errors' => []]);
    }

    public function forgotSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        if (trim($email) === '') {
            return $this->view->render('auth/forgot.twig', [
                'page_title' => 'Reset your password',
                'sent' => false,
                'old' => [],
                'errors' => ['email' => 'Enter your email address.'],
            ], 422);
        }

        $this->request->execute($email);

        // The same answer whether or not the address belongs to an account.
        return $this->view->render('auth/forgot.twig', ['page_title' => 'Check your email', 'sent' => true, 'old' => [], 'errors' => []]);
    }

    /** @param array<string, string> $args */
    public function resetForm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $live = $this->tokens->peek($token, AuthTokenPurpose::PasswordReset, $this->clock->now()) !== null;

        return $this->view->render('auth/reset.twig', [
            'page_title' => $live ? 'Choose a new password' : 'Link not valid',
            'token' => $token,
            'invalid' => !$live,
            'errors' => [],
        ], $live ? 200 : 410);
    }

    /** @param array<string, string> $args */
    public function resetSubmit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $body = (array) $request->getParsedBody();
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirmation = is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '';

        try {
            $this->complete->execute($token, $password, $confirmation, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->view->render('auth/reset.twig', [
                'page_title' => 'Choose a new password',
                'token' => $token,
                'invalid' => false,
                'errors' => $e->errors,
            ], 422);
        } catch (InvalidToken) {
            return $this->view->render('auth/reset.twig', [
                'page_title' => 'Link not valid',
                'token' => $token,
                'invalid' => true,
                'errors' => [],
            ], 410);
        }

        return $this->redirect->to('/login?notice=reset');
    }
}
