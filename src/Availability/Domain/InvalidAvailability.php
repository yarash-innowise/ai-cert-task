<?php

declare(strict_types=1);

namespace App\Availability\Domain;

/** A week that cannot be saved; every message names the day and the problem. */
final class InvalidAvailability extends \DomainException
{
    /** @param array<string, string> $errors "day-N" => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
