<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AssignmentAlreadyExists;
use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\CoachAssignment;
use App\Availability\Domain\CoachAssignments;
use App\Availability\Domain\Weekday;
use App\Identity\Domain\CurrentUser;
use App\Organization\Application\ListOrganizationCoaches;
use App\Organization\Application\RosterCoach;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A Trainer assigns a Coach of their own organization to an event (AC-65..AC-67, AC-87).
 *
 * The Coach's schedule is advisory but is checked: when the event is not inside their saved times the Trainer
 * gets the warning and nothing is written. Continuing needs a reason that is not blank after trimming; then the
 * override (event, Coach, reason, Trainer) is stored and audit-logged together with the assignment. The Coach is
 * told either way (Q-01.06) and sees the assignment on their dashboard, where they accept or ask for a change.
 * A Coach with no schedule at all gave no preference, so there is nothing to conflict with.
 */
final class AssignCoach
{
    public const MAX_REASON = 500;

    public function __construct(
        private readonly ListOrganizationCoaches $coaches,
        private readonly AvailabilityRepository $availability,
        private readonly CoachAssignments $assignments,
        private readonly OrganizationRepository $organizations,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly TransactionManager $transaction,
        private readonly AppUrl $urls,
    ) {}

    /** The exact warning of AC-65. */
    public static function warning(string $coachName): string
    {
        return sprintf('Coach %s is not available at this time per their schedule. Continue anyway?', $coachName);
    }

    /**
     * @throws NotFound not a Coach of this Trainer's organization
     * @throws ValidationFailed inactive Coach, reason too long, or the Coach already has this event
     * @throws \App\Shared\Http\Exception\Forbidden not this organization's Trainer
     */
    public function execute(CurrentUser $actor, RequestContext $context, AssignCoachInput $input, ?string $ip = null): AssignOutcome
    {
        $coach = $this->coaches->find($actor, $context, $input->coachUserId);
        $organization = $context->organizationId ?? throw new NotFound();
        if ($coach->inactive) {
            throw ValidationFailed::forField('coach', 'This Coach is inactive and cannot be assigned.');
        }

        $week = $this->availability->week(AvailabilitySubject::Coach, $coach->userId);
        $conflict = $week->hasEntries() && !$week->covers(Weekday::ofDate($input->eventDate), $input->window);

        $reason = null;
        if ($conflict) {
            $warning = self::warning($coach->name);
            if (!$input->continueAnyway) {
                return AssignOutcome::conflict($warning);
            }
            $reason = trim((string) $input->reason);
            if ($reason === '') {
                return AssignOutcome::conflict($warning, 'A reason is required to continue.');
            }
            if (mb_strlen($reason) > self::MAX_REASON) {
                throw ValidationFailed::forField('reason', sprintf('Keep the reason under %d characters.', self::MAX_REASON));
            }
        }

        try {
            $assignment = $this->transaction->run(fn(): CoachAssignment => $this->store($actor, $organization, $coach, $input, $reason, $ip));
        } catch (AssignmentAlreadyExists) {
            throw ValidationFailed::forField('coach', 'This Coach is already assigned to this event.');
        }

        return AssignOutcome::assigned($assignment);
    }

    private function store(CurrentUser $actor, OrganizationId $organization, RosterCoach $coach, AssignCoachInput $input, ?string $reason, ?string $ip): CoachAssignment
    {
        $overrideId = $reason === null
            ? null
            : $this->assignments->recordOverride($organization, $input->eventRef, $coach->userId, $reason, $actor->id);
        $assignment = $this->assignments->create($organization, $input->eventRef, $input->eventDate, $input->window, $coach->userId, $actor->id, $overrideId);
        $businessName = $this->organizations->findById($organization->value)->businessName ?? '';

        if ($overrideId !== null) {
            $this->audit->log('coach.availability_override', $actor->id, 'coach_assignment', $assignment->id, $organization->value, [
                'override_id' => $overrideId,
                'event_ref' => $assignment->eventRef,
                'coach_user_id' => $coach->userId,
                'overridden_by' => $actor->id,
                'business_name' => $businessName,
            ], null, $ip);
        }

        $title = 'You were assigned to ' . $assignment->eventRef;
        $body = sprintf('%s assigned you to %s on %s.', $businessName, $assignment->eventRef, $assignment->when())
            . ($overrideId === null ? '' : ' This is outside your saved schedule; you can accept it or ask for a change.');
        $this->notifications->notify(
            $coach->userId,
            'coach_assignment',
            $title,
            $body,
            ['assignment_id' => $assignment->id, 'organization_id' => $organization->value, 'override' => $overrideId !== null],
            new EmailMessage('coach-assignment', $coach->email, [
                'recipient_name' => $coach->name,
                'event_ref' => $assignment->eventRef,
                'event_when' => $assignment->when(),
                'business_name' => $businessName,
                'outside_schedule' => $overrideId !== null,
                'view_url' => $this->urls->absolute('/coach'),
            ]),
        );

        return $assignment;
    }
}
