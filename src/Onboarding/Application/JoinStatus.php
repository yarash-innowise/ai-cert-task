<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

enum JoinStatus
{
    /** The player was associated just now. */
    case Joined;
    /** The player already belongs to this Trainer: nothing was written. */
    case AlreadyAssociated;
    /** The account has children: the "Who will train with [Trainer]?" checklist must be answered. */
    case ChooseWho;
    /** The account has no player profile at all: it must add one first. */
    case NeedsProfile;
}
