<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\EndImpersonation;
use App\Admin\Application\StartImpersonation;
use App\Admin\Domain\ImpersonationContext;
use App\Admin\Domain\ImpersonationEndReason;
use App\Admin\Domain\ImpersonationRepository;
use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Http\CookieHeader;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Start (after a confirmation naming the user and role) and exit of an impersonation. Starting is in the Super Admin
 * route group; exiting cannot be, because while impersonating the effective user is the impersonated one, so it only
 * needs a session and the use case checks that the session really is an impersonation.
 */
final class ImpersonationController
{
    private const TEMPLATE = 'admin/users/impersonate-confirm.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly StartImpersonation $start,
        private readonly EndImpersonation $end,
        private readonly ImpersonationRepository $impersonations,
        private readonly UserRepository $users,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function confirm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        try {
            $target = $this->start->preview($actor, $this->state($request), (string) ($args['id'] ?? ''));
        } catch (ValidationFailed $e) {
            return $this->refused($args, $e);
        }

        return $this->render($target, [], 200);
    }

    /** @param array<string, string> $args */
    public function start(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        try {
            $impersonation = $this->start->execute($actor, $this->state($request), (string) ($args['id'] ?? ''), $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->refused($args, $e);
        }

        // The session now shows the target: the admin lands on that user's own start page. The marker cookie lives
        // only as long as the browser does, so closing the browser ends the impersonation (edge case).
        $target = $this->users->findById($impersonation->targetUserId) ?? throw new NotFound();

        return $this->redirect->to($target->role->dashboardPath())
            ->withAddedHeader('Set-Cookie', CookieHeader::set(ImpersonationMiddleware::COOKIE, $impersonation->id));
    }

    public function exit(ServerRequestInterface $request): ResponseInterface
    {
        $context = $request->getAttribute(ImpersonationMiddleware::ATTRIBUTE);
        if (!$context instanceof ImpersonationContext) {
            throw new NotFound();
        }
        $impersonation = $this->impersonations->find($context->id) ?? throw new NotFound();
        $this->end->execute($this->state($request), $impersonation, ImpersonationEndReason::Manual, null, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/admin/users?notice=impersonation_ended')
            ->withAddedHeader('Set-Cookie', CookieHeader::clear(ImpersonationMiddleware::COOKIE));
    }

    /** @param array<string, string> $args */
    private function refused(array $args, ValidationFailed $e): ResponseInterface
    {
        $target = $this->users->findById((string) ($args['id'] ?? '')) ?? throw new NotFound();

        return $this->render($target, $e->errors, 422);
    }

    /** @param array<string, string> $errors */
    private function render(User $target, array $errors, int $status): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, [
            'page_title' => 'Impersonate user',
            'subject' => $target,
            'errors' => $errors,
            'form_error' => $errors['user'] ?? null,
        ], $status);
    }

    private function actor(ServerRequestInterface $request): CurrentUser
    {
        $actor = $request->getAttribute('current_user');

        return $actor instanceof CurrentUser ? $actor : throw new Forbidden();
    }

    private function state(ServerRequestInterface $request): SessionState
    {
        $state = $request->getAttribute('session_state');

        return $state instanceof SessionState ? $state : throw new \LogicException('SessionMiddleware is not in the pipeline.');
    }
}
