<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

/** One person on the "Who will train with [Trainer]?" checklist. */
final readonly class ChecklistOption
{
    public function __construct(
        public string $profileId,
        public string $label,
        public bool $isMe,
        public bool $alreadyAssociated,
    ) {}
}
