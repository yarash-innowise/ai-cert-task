<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use DateTimeImmutable;

/** A usable camp-form pre-fill: the organization it leads to and the values to put in the form. */
final readonly class RegistrationPrefill
{
    /** @param array<string, string> $fields pre-fill values by form field name */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $businessName,
        public array $fields,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function email(): string
    {
        return $this->fields['email'] ?? '';
    }
}
