<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AssignmentStatus;
use App\Availability\Domain\CoachAssignment;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\RequestContext;

/** The Coach asks the Trainer for a change instead of accepting (AC-67); the Trainer is notified. */
final class RequestAssignmentChange
{
    public const MAX_NOTE = 500;

    public function __construct(private readonly AssignmentResponder $responder) {}

    /** @throws ValidationFailed the note is too long */
    public function execute(CurrentUser $actor, RequestContext $context, string $assignmentId, string $note): CoachAssignment
    {
        $note = trim($note);
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationFailed::forField('note', sprintf('Keep the note under %d characters.', self::MAX_NOTE));
        }

        return $this->responder->respond($actor, $context, $assignmentId, AssignmentStatus::ChangeRequested, $note === '' ? null : $note);
    }
}
