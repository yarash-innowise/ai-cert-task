<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Http\Exception\ValidationFailed;

/**
 * Email address, trimmed and lowercased so uniqueness is case-insensitive.
 */
final readonly class Email implements \Stringable
{
    private function __construct(public string $value) {}

    public static function fromString(string $raw, string $field = 'email'): self
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '') {
            throw ValidationFailed::forField($field, 'Email is required.');
        }
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationFailed::forField($field, 'Enter a valid email address.');
        }

        return new self($value);
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
