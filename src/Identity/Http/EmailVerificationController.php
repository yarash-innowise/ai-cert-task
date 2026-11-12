<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\IssueEmailVerification;
use App\Identity\Application\VerifyEmail;
use App\Identity\Domain\InvalidToken;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The emailed link opens a confirmation page; the address is confirmed by the POST behind its
 * button, so mail scanners that prefetch links cannot consume the token.
 */
final class EmailVerificationController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly VerifyEmail $verify,
        private readonly IssueEmailVerification $issue,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $live = $this->verify->isLive($token);

        return $this->view->render('auth/verify.twig', [
            'page_title' => $live ? 'Confirm your email' : 'Link not valid',
            'token' => $token,
            'invalid' => !$live,
        ], $live ? 200 : 410);
    }

    /** @param array<string, string> $args */
    public function confirm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        try {
            $this->verify->execute($token);
        } catch (InvalidToken) {
            return $this->view->render('auth/verify.twig', [
                'page_title' => 'Link not valid',
                'token' => $token,
                'invalid' => true,
            ], 410);
        }

        return $this->redirect->to('/login?notice=verified');
    }

    public function resendForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('auth/resend-verification.twig', ['page_title' => 'Resend the verification email', 'sent' => false, 'old' => [], 'errors' => []]);
    }

    public function resendSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        if (trim($email) === '') {
            return $this->view->render('auth/resend-verification.twig', [
                'page_title' => 'Resend the verification email',
                'sent' => false,
                'old' => [],
                'errors' => ['email' => 'Enter your email address.'],
            ], 422);
        }

        $this->issue->resendFor($email);

        return $this->view->render('auth/resend-verification.twig', ['page_title' => 'Check your email', 'sent' => true, 'old' => [], 'errors' => []]);
    }
}
