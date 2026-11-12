<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testV7HasVersionAndIsTimeOrdered(): void
    {
        $a = Uuid::v7(1_000);
        $b = Uuid::v7(2_000);
        self::assertSame('7', $a->value[14]);
        self::assertLessThan(0, strcmp($a->value, $b->value));
        self::assertTrue(Uuid::fromString(strtoupper($a->value))->equals($a));
        self::assertNull(Uuid::tryFromString('nope'));
    }
}
