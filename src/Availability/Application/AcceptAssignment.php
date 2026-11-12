<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AssignmentStatus;
use App\Availability\Domain\CoachAssignment;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\RequestContext;

/** The Coach accepts an assignment, also one that was made outside their schedule (AC-67). */
final class AcceptAssignment
{
    public function __construct(private readonly AssignmentResponder $responder) {}

    public function execute(CurrentUser $actor, RequestContext $context, string $assignmentId): CoachAssignment
    {
        return $this->responder->respond($actor, $context, $assignmentId, AssignmentStatus::Accepted, null);
    }
}
