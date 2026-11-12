<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Shared\Http\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the CurrentUser from the session. A session whose user is inactive or deleted is ended
 * on the spot (AC-73); a user who still has to choose a password may only reach that page.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const SET_PASSWORD_PATH = '/password/set';
    private const ALLOWED_WHILE_PASSWORD_UNSET = [self::SET_PASSWORD_PATH, '/logout'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly CurrentUserHolder $holder,
        private readonly Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $request->getAttribute('session_state');
        $user = null;
        $endedBecause = null;

        if ($state instanceof SessionState && ($session = $state->session()) !== null) {
            $record = $this->users->findById($session->userId);
            // An impersonated user may be inactive (support view, Q-01.16) or deleted meanwhile; the impersonation
            // middleware decides what then happens, so the administrator is not signed out with them.
            $viewing = $session->isImpersonating() && $record !== null;
            if ($record === null || (!$record->isActive() && !$viewing)) {
                $state->end();
                $endedBecause = 'deactivated';
            } else {
                $user = CurrentUser::fromUser($record);
            }
        }

        $this->holder->set($user);
        $request = $request
            ->withAttribute('current_user', $user)
            ->withAttribute('session_ended_because', $endedBecause);

        // A Super Admin viewing as the user must not be sent to (or act on) the user's first-password page.
        $impersonating = $state instanceof SessionState && $state->session()?->isImpersonating() === true;
        if ($user !== null && $user->mustSetPassword && !$impersonating
            && !in_array($request->getUri()->getPath(), self::ALLOWED_WHILE_PASSWORD_UNSET, true)) {
            return $this->redirect->to(self::SET_PASSWORD_PATH);
        }

        return $handler->handle($request);
    }
}
