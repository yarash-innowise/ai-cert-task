<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Family\Application\SwitchContext;
use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Onboarding\Application\BlockChildLink;
use App\Onboarding\Application\JoinAsPlayer;
use App\Onboarding\Application\JoinFamilyChecklist;
use App\Onboarding\Application\JoinStatus;
use App\Onboarding\Application\ResolveShareLink;
use App\Onboarding\Domain\ResolvedShareLink;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Landing page of a player ShareLink. Every unusable code (unknown, deactivated, replaced,
 * inactive organization, deactivated or deleted Trainer) shows the same message and writes nothing
 * (AC-26). Opening a link (GET) never writes. What a valid link does depends on who opens it:
 *
 *  - a visitor: register or sign in (AC-19);
 *  - an adult player / parent: a confirmation page (or the family checklist); the POST associates (AC-21, AC-22);
 *  - a child login: a confirmation page; the POST tells the parent, nothing is associated (AC-49);
 *  - a Trainer, Coach or Super Admin: "this link is for players".
 */
final class JoinController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ResolveShareLink $resolve,
        private readonly JoinAsPlayer $joinAsPlayer,
        private readonly JoinFamilyChecklist $checklist,
        private readonly BlockChildLink $blockChild,
        private readonly SwitchContext $switchContext,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $resolved = $this->resolve->execute((string) ($args['code'] ?? ''));
        if ($resolved === null) {
            return $this->invalid();
        }
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser) {
            return $this->view->render('onboarding/join.twig', [
                'page_title' => 'Join ' . $resolved->businessName,
                'business_name' => $resolved->businessName,
                'code' => $resolved->link->code,
            ]);
        }
        if ($user->role !== Role::Player) {
            return $this->view->render('onboarding/players-only.twig', [
                'page_title' => 'This link is for players',
                'business_name' => $resolved->businessName,
                'dashboard_path' => $user->role->dashboardPath(),
            ]);
        }
        // Opening the link only asks: nothing is written until the POST below (F-04, AC-21, AC-49)
        if ($user->isChildLogin()) {
            return $this->view->render('onboarding/join-confirm.twig', [
                'page_title' => 'Ask your parent to join ' . $resolved->businessName,
                'business_name' => $resolved->businessName,
                'code' => $resolved->link->code,
                'is_child' => true,
            ]);
        }
        if ($this->joinAsPlayer->needsChecklist($user, $resolved)) {
            return $this->renderChecklist($user, $resolved, [], 200);
        }

        return $this->view->render('onboarding/join-confirm.twig', [
            'page_title' => 'Join ' . $resolved->businessName,
            'business_name' => $resolved->businessName,
            'code' => $resolved->link->code,
            'is_child' => false,
        ]);
    }

    /**
     * The one-click "Join [Trainer]" confirmation (AC-21) and the child's request to the parent (AC-49).
     * A CSRF-protected POST behind the auth stack, so impersonation read-only rules and audit apply (AC-55).
     *
     * @param array<string, string> $args
     */
    public function confirm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $resolved = $this->resolve->execute((string) ($args['code'] ?? ''));
        if ($resolved === null) {
            return $this->invalid();
        }
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser) {
            throw new Forbidden();
        }
        if ($user->isChildLogin()) {
            $this->blockChild->execute($user, $resolved, $this->clientIp->fromRequest($request));

            return $this->view->render('onboarding/child-blocked.twig', [
                'page_title' => 'Ask your parent',
                'business_name' => $resolved->businessName,
            ]);
        }

        $result = $this->joinAsPlayer->execute($user, $resolved, $this->clientIp->fromRequest($request));

        return match ($result->status) {
            JoinStatus::Joined => $this->arrive($request, $user, $resolved, $result->profileId, 'joined'),
            JoinStatus::AlreadyAssociated => $this->arrive($request, $user, $resolved, $result->profileId, 'already_associated'),
            JoinStatus::ChooseWho => $this->renderChecklist($user, $resolved, [], 200),
            JoinStatus::NeedsProfile => $this->redirect->to('/family/me'),
        };
    }

    /**
     * Answer of the "Who will train?" checklist.
     *
     * @param array<string, string> $args
     */
    public function who(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $resolved = $this->resolve->execute((string) ($args['code'] ?? ''));
        if ($resolved === null) {
            return $this->invalid();
        }
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser) {
            throw new Forbidden();
        }
        $body = (array) $request->getParsedBody();
        $ids = is_array($body['profile_ids'] ?? null) ? array_values(array_filter($body['profile_ids'], 'is_string')) : [];

        try {
            $added = $this->checklist->submit($user, $resolved, $ids, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->renderChecklist($user, $resolved, $e->errors, 422);
        }

        return $this->arrive($request, $user, $resolved, $added[0] ?? $ids[0], $added === [] ? 'already_associated' : 'joined');
    }

    /** Sends the player to that Trainer's events, with that Trainer as the active context. */
    private function arrive(ServerRequestInterface $request, CurrentUser $user, ResolvedShareLink $resolved, ?string $profileId, string $notice): ResponseInterface
    {
        $state = $request->getAttribute('session_state');
        if ($state instanceof SessionState && $profileId !== null) {
            $this->switchContext->execute($state, $user, $resolved->link->organizationId, $profileId);
        }

        return $this->redirect->to('/family/events?notice=' . $notice);
    }

    /** @param array<string, string> $errors */
    private function renderChecklist(CurrentUser $user, ResolvedShareLink $resolved, array $errors, int $status): ResponseInterface
    {
        return $this->view->render('onboarding/who-will-train.twig', [
            'page_title' => 'Who will train with ' . $resolved->businessName . '?',
            'business_name' => $resolved->businessName,
            'code' => $resolved->link->code,
            'options' => $this->checklist->options($user, $resolved),
            'errors' => $errors,
        ], $status);
    }

    private function invalid(): ResponseInterface
    {
        return $this->view->render('onboarding/invalid-link.twig', ['page_title' => 'Link not valid'], 404);
    }
}
