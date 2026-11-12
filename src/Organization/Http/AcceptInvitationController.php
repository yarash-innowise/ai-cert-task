<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Organization\Application\AcceptCoachInvitation;
use App\Organization\Application\CoachRegistration;
use App\Organization\Application\ResolveCoachInvitation;
use App\Organization\Domain\InvitationRefusal;
use App\Organization\Domain\InvitationRefused;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/invitations/{token}`: the public landing page of a Coach invitation link and its acceptance.
 * Every unusable link (unknown, used, expired, replaced) shows a clear message and associates
 * nobody (AC-26); an expired link tells the Coach to ask the Trainer, who can resend (AC-60).
 */
final class AcceptInvitationController
{
    private const INVALID = 'organization/invitation-invalid.twig';
    private const SHOW = 'organization/invitation.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ResolveCoachInvitation $resolve,
        private readonly AcceptCoachInvitation $accept,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        [$resolved, $refusal] = $this->resolve->execute($token);
        if ($resolved === null || $refusal !== null) {
            return $this->refused($refusal ?? InvitationRefusal::Unknown);
        }

        $actor = $request->getAttribute('current_user');
        $actor = $actor instanceof CurrentUser ? $actor : null;
        $mismatch = null;
        if ($actor !== null && $actor->role !== Role::Coach) {
            $mismatch = InvitationRefusal::NotACoachAccount;
        } elseif ($actor !== null && $actor->email !== $resolved->invitation->email) {
            $mismatch = InvitationRefusal::WrongAccount;
        }

        return $this->view->render(self::SHOW, [
            'page_title' => 'Coach invitation',
            'token' => $token,
            'business_name' => $resolved->businessName,
            'invited_email' => $resolved->invitation->email,
            'invited_name' => $resolved->invitation->name,
            'message' => $resolved->invitation->message,
            'signed_in' => $actor !== null,
            'mismatch' => $mismatch?->message(),
            'old' => ['name' => $resolved->invitation->name ?? ''],
            'errors' => [],
        ]);
    }

    /** @param array<string, string> $args */
    public function accept(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $actor = $request->getAttribute('current_user');
        $actor = $actor instanceof CurrentUser ? $actor : null;
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        try {
            $registration = $actor === null ? new CoachRegistration($name, $password) : null;
            $result = $this->accept->execute($token, $actor, $registration, $this->clientIp->fromRequest($request));
        } catch (InvitationRefused $e) {
            return $this->refused($e->reason);
        } catch (ValidationFailed $e) {
            [$resolved] = $this->resolve->execute($token);
            if ($resolved === null) {
                return $this->refused(InvitationRefusal::Unknown);
            }

            return $this->view->render(self::SHOW, [
                'page_title' => 'Coach invitation',
                'token' => $token,
                'business_name' => $resolved->businessName,
                'invited_email' => $resolved->invitation->email,
                'invited_name' => $resolved->invitation->name,
                'message' => $resolved->invitation->message,
                'signed_in' => false,
                'mismatch' => null,
                'old' => ['name' => $name],
                'errors' => $e->errors,
            ], 422);
        }

        if ($result->registered) {
            $state = $request->getAttribute('session_state');
            if (!$state instanceof SessionState) {
                throw new \LogicException('SessionMiddleware is not in the pipeline.');
            }
            $state->start($result->coachUserId);
        }

        return $this->redirect->to('/coach');
    }

    private function refused(InvitationRefusal $reason): ResponseInterface
    {
        $status = $reason === InvitationRefusal::Unknown ? 404 : 410;
        if (in_array($reason, [InvitationRefusal::WrongAccount, InvitationRefusal::NotACoachAccount, InvitationRefusal::AccountExists, InvitationRefusal::CoachAlreadyActive], true)) {
            $status = 409;
        }

        return $this->view->render(self::INVALID, [
            'page_title' => 'Invitation cannot be used',
            'reason' => $reason->value,
            'message' => $reason->message(),
        ], $status);
    }
}
