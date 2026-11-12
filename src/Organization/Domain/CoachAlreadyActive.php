<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/** The Coach is already active under a Trainer (the partial unique index rejected a second active membership). */
final class CoachAlreadyActive extends \RuntimeException
{
    public const MESSAGE = 'You are already an active Coach under another Trainer. Ask that Trainer to release you before you accept a new invitation.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
