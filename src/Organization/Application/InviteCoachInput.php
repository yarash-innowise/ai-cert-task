<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Shared\Domain\Email;

/** A validated invitation form: the email is required, the name and the personal message are optional (AC-57). */
final readonly class InviteCoachInput
{
    public function __construct(
        public Email $email,
        public ?string $name = null,
        public ?string $message = null,
    ) {}
}
