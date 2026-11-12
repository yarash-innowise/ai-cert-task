<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AssignmentStatus;
use App\Availability\Domain\CoachAssignment;
use App\Availability\Domain\CoachAssignments;
use App\Availability\Domain\Policy\AssignmentPolicy;
use App\Identity\Domain\CurrentUser;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/** Shared by the two Coach answers: finds the Coach's own assignment (404 otherwise) and records the answer. */
final class AssignmentResponder
{
    public function __construct(
        private readonly CoachAssignments $assignments,
        private readonly AssignmentPolicy $policy,
        private readonly OrganizationRepository $organizations,
        private readonly NotificationService $notifications,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws NotFound not the actor's assignment (another Coach's or another organization's looks the same) */
    public function respond(CurrentUser $actor, RequestContext $context, string $assignmentId, AssignmentStatus $status, ?string $note): CoachAssignment
    {
        $organization = $context->organizationId ?? throw new NotFound();
        $assignment = $this->assignments->find($organization, $assignmentId);
        if ($assignment === null || !$this->policy->canRespond($actor, $context, $assignment)) {
            throw new NotFound();
        }

        $this->transaction->run(function () use ($organization, $assignment, $actor, $status, $note): void {
            $this->assignments->respond($organization, $assignment->id, $status, $note, $this->clock->now());
            if ($status === AssignmentStatus::ChangeRequested) {
                $owner = $this->organizations->findById($organization->value)?->ownerUserId;
                if ($owner !== null) {
                    $this->notifications->notify(
                        $owner,
                        'coach_change_requested',
                        sprintf('%s asked for a change to %s', $actor->name, $assignment->eventRef),
                        $note ?? '',
                        ['assignment_id' => $assignment->id, 'coach_user_id' => $actor->id],
                    );
                }
            }
        });

        return $this->assignments->find($organization, $assignment->id) ?? throw new NotFound();
    }
}
