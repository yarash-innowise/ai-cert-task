<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Family\Http\ActorAware;
use App\Organization\Application\InviteCoach;
use App\Organization\Application\ListCoachInvitations;
use App\Organization\Application\ListOrganizationCoaches;
use App\Organization\Application\ResendCoachInvitation;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Trainer's Coaches page: invite a Coach by email, see every invitation with its status, resend an
 * expired (or pending) one. The organization is always the one of the request context, never the URL.
 */
final class TrainerCoachesController
{
    use ActorAware;

    private const TEMPLATE = 'trainer/coaches.twig';
    private const NOTICES = [
        'invited' => 'The invitation was sent.',
        'resent' => 'A new invitation was sent. The old link no longer works.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListCoachInvitations $listInvitations,
        private readonly ListOrganizationCoaches $coaches,
        private readonly InviteCoach $inviteCoach,
        private readonly ResendCoachInvitation $resendInvitation,
        private readonly ClientIp $clientIp,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render($request, [], [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    public function invite(ServerRequestInterface $request): ResponseInterface
    {
        $input = null;
        try {
            $input = InviteCoachRequest::fromArray($this->body($request));
            $this->inviteCoach->execute($this->actor($request), RequestContext::from($request), $input, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            $old = $e->old !== [] ? $e->old : ['email' => $input?->email->value ?? ''];

            return $this->render($request, $old, $e->errors, 422, null);
        }

        return $this->redirect->to('/trainer/coaches?notice=invited');
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $coach = $this->coaches->find($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->view->render('trainer/coach.twig', ['page_title' => $coach->name, 'coach' => $coach]);
    }

    /** @param array<string, string> $args */
    public function resend(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $this->resendInvitation->execute($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/trainer/coaches?notice=resent');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function render(ServerRequestInterface $request, array $old, array $errors, int $status, ?string $flash): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, [
            'page_title' => 'Coaches',
            'coaches' => $this->coaches->execute($this->actor($request), RequestContext::from($request)),
            'invitations' => $this->listInvitations->execute($this->actor($request), RequestContext::from($request)),
            'old' => $old,
            'errors' => $errors,
            'flash' => $flash,
        ], $status);
    }
}
