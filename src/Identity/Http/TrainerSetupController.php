<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\CompleteTrainerSetup;
use App\Identity\Application\SessionState;
use App\Identity\Domain\InvalidToken;
use App\Identity\Domain\Role;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** `/setup/{token}`: a new Trainer chooses a password from the emailed link and lands on the Trainer dashboard. */
final class TrainerSetupController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly CompleteTrainerSetup $setup,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $live = $this->setup->isLive($token);

        return $this->render($token, !$live, [], $live ? 200 : 410);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $body = (array) $request->getParsedBody();
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $confirmation = is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '';

        try {
            $userId = $this->setup->execute($token, $password, $confirmation, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($token, false, $e->errors, 422);
        } catch (InvalidToken) {
            return $this->render($token, true, [], 410);
        }

        $state = $request->getAttribute('session_state');
        if (!$state instanceof SessionState) {
            throw new \LogicException('SessionMiddleware is not in the pipeline.');
        }
        $state->start($userId);

        return $this->redirect->to(Role::Trainer->dashboardPath());
    }

    /** @param array<string, string> $errors */
    private function render(string $token, bool $invalid, array $errors, int $status): ResponseInterface
    {
        return $this->view->render('auth/setup.twig', [
            'page_title' => $invalid ? 'Link not valid' : 'Set up your account',
            'token' => $token,
            'invalid' => $invalid,
            'errors' => $errors,
        ], $status);
    }
}
