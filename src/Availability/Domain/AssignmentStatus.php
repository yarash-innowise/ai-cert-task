<?php

declare(strict_types=1);

namespace App\Availability\Domain;

enum AssignmentStatus: string
{
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case ChangeRequested = 'change_requested';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Waiting for the Coach',
            self::Accepted => 'Accepted',
            self::ChangeRequested => 'Change requested',
        };
    }
}
