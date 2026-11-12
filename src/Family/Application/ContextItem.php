<?php

declare(strict_types=1);

namespace App\Family\Application;

/** One selectable context; `value` is what the selector form posts back. */
final readonly class ContextItem
{
    public function __construct(
        public string $organizationId,
        public string $profileId,
        public string $profileName,
        public string $businessName,
        public string $label,
        public bool $active,
    ) {}

    public function value(): string
    {
        return $this->organizationId . ':' . $this->profileId;
    }
}
