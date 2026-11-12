<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;

/** Validated input of the "create Trainer" form (all four fields are required). */
final readonly class CreateTrainerInput
{
    public function __construct(
        public string $businessName,
        public string $trainerName,
        public Email $email,
        public Phone $phone,
    ) {}
}
