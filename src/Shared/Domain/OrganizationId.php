<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Identifier of a tenant (a Trainer's organization). Tenant repositories take this type, never a
 * bare string, so a method that forgets the organization does not compile (design D8).
 */
final readonly class OrganizationId implements \Stringable
{
    private function __construct(public string $value) {}

    /** @throws \InvalidArgumentException when the value is not a UUID */
    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value)->value);
    }

    public static function tryFromString(string $value): ?self
    {
        $uuid = Uuid::tryFromString($value);

        return $uuid === null ? null : new self($uuid->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
