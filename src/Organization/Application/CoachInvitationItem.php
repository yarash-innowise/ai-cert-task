<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Domain\CoachInvitation;
use App\Organization\Domain\InvitationStatus;

final readonly class CoachInvitationItem
{
    public function __construct(public CoachInvitation $invitation, public InvitationStatus $status) {}
}
