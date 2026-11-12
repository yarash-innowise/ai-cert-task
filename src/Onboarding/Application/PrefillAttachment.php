<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

/** What happened when a signed-in player opened a pre-fill link. */
enum PrefillAttachment
{
    case Attached;
    case AlreadyAssociated;
    /** The pre-fill belongs to another email address (or was used meanwhile): nothing was written. */
    case WrongAccount;
    /** The account has no "Me" profile to associate: nothing was consumed, the person completes the profile first. */
    case NeedsProfile;
}
