<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\HexColor;
use App\Shared\Http\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class HexColorTest extends TestCase
{
    #[Group('AC-82')]
    public function testValidHexAccepted(): void
    {
        self::assertSame('#1A7F37', HexColor::fromString('#1a7f37')->value);
    }

    #[Group('AC-82')]
    #[DataProvider('invalid')]
    public function testInvalidHexRejected(string $raw): void
    {
        $this->expectException(ValidationFailed::class);
        HexColor::fromString($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'no hash' => ['1A7F37'];
        yield 'short' => ['#fff'];
        yield 'non hex' => ['#GGGGGG'];
        yield 'too long' => ['#1A7F37FF'];
        yield 'empty' => [''];
    }
}
