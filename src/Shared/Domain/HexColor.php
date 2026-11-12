<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Http\Exception\ValidationFailed;

final readonly class HexColor implements \Stringable
{
    private function __construct(public string $value) {}

    public static function fromString(string $raw, string $field = 'brand_color'): self
    {
        $raw = trim($raw);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) !== 1) {
            throw ValidationFailed::forField($field, 'Enter a color as a hex code, for example #1A7F37.');
        }

        return new self(strtoupper($raw));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
