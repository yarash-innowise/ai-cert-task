<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Http\Exception\ValidationFailed;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone number normalized to E.164.
 */
final readonly class Phone implements \Stringable
{
    private function __construct(public string $e164) {}

    public static function fromString(string $raw, string $field = 'phone', string $defaultRegion = 'US'): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw ValidationFailed::forField($field, 'Phone number is required.');
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($raw, $defaultRegion);
        } catch (NumberParseException) {
            throw ValidationFailed::forField($field, 'Enter a valid phone number, for example +1 415 555 2671.');
        }
        if (!$util->isValidNumber($number)) {
            throw ValidationFailed::forField($field, 'Enter a valid phone number, for example +1 415 555 2671.');
        }

        return new self($util->format($number, PhoneNumberFormat::E164));
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
