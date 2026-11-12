<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Domain\CoachInvitation;
use App\Organization\Domain\CoachInvitationRepository;
use App\Organization\Domain\CoachInvitationToken;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\AppUrl;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;

/**
 * Creates one invitation (single use, valid 7 days from now), replaces any still-pending invitation
 * of the same organization to the same email and queues the invitation email. Runs inside the
 * caller's transaction so the email exists only if the invitation does (D12).
 */
final class IssueCoachInvitation
{
    public function __construct(
        private readonly CoachInvitationRepository $invitations,
        private readonly OrganizationRepository $organizations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    public function execute(OrganizationId $organization, string $createdBy, Email $email, ?string $name, ?string $message, ?string $ip = null): CoachInvitation
    {
        $now = $this->clock->now();
        $businessName = $this->organizations->findById($organization->value)->businessName
            ?? throw new \LogicException('Organization of the invitation does not exist.');

        $this->invitations->revokePendingFor($organization, $email, $now);
        $token = CoachInvitationToken::generate();
        $invitation = $this->invitations->create(
            $organization,
            $email,
            $name,
            $message,
            $createdBy,
            CoachInvitationToken::hash($token),
            $now,
            $now->add(new \DateInterval(CoachInvitation::VALID_FOR)),
        );
        $this->notifications->email(new EmailMessage('coach-invitation', $email->value, [
            'coach_name' => $name,
            'business_name' => $businessName,
            'message' => $message,
            'accept_url' => $this->urls->absolute('/invitations/' . $token),
            'expires_on' => $invitation->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
        ]));
        $this->audit->log('coach.invited', $createdBy, 'coach_invitation', $invitation->id, $organization->value, ['invitation_id' => $invitation->id, 'trainer' => $businessName], null, $ip);

        return $invitation;
    }
}
