<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\CoachAssignment;

/**
 * Either the assignment was made, or nothing was written and the Trainer must decide: `warning` is the
 * schedule conflict question, `reasonError` is set when "continue" came without a usable reason.
 */
final readonly class AssignOutcome
{
    private function __construct(
        public ?CoachAssignment $assignment,
        public ?string $warning,
        public ?string $reasonError,
    ) {}

    public static function assigned(CoachAssignment $assignment): self
    {
        return new self($assignment, null, null);
    }

    public static function conflict(string $warning, ?string $reasonError = null): self
    {
        return new self(null, $warning, $reasonError);
    }

    public function wasAssigned(): bool
    {
        return $this->assignment !== null;
    }
}
