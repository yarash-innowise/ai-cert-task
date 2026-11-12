<?php

declare(strict_types=1);

namespace App\Family\Application;

/** Validated input of the edit-child form (name, school, jersey number). */
final readonly class EditChildInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $school,
        public ?int $jerseyNumber,
    ) {}
}
