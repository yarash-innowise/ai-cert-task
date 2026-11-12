<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Phone;
use App\Shared\Http\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    #[Group('AC-70')]
    public function testValidNumberNormalizedToE164(): void
    {
        self::assertSame('+14155552671', Phone::fromString('(415) 555-2671')->e164);
        self::assertSame('+442071838750', Phone::fromString('+44 20 7183 8750')->e164);
    }

    #[Group('AC-70')]
    #[DataProvider('invalid')]
    public function testInvalidPhoneNamesTheField(string $raw, string $field): void
    {
        try {
            Phone::fromString($raw, $field);
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'letters' => ['abc', 'phone'];
        yield 'too short' => ['123', 'phone'];
        yield 'empty' => ['', 'emergency_phone'];
    }
}
