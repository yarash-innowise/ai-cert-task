<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** One context a user may act in: an organization, optionally as one of their player profiles. */
final readonly class ContextOption
{
    public function __construct(
        public OrganizationId $organizationId,
        public ?string $profileId = null,
    ) {}

    public function equals(self $other): bool
    {
        return $this->organizationId->equals($other->organizationId) && $this->profileId === $other->profileId;
    }
}
