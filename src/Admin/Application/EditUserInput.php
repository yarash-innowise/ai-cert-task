<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;

/** Validated edit of a user's account fields. Role and status are deliberately absent (not editable here). */
final readonly class EditUserInput
{
    public function __construct(
        public string $name,
        public ?Phone $phone,
        public Email $email,
        public int $version,
    ) {}
}
