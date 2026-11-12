<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Group('AC-88')]
    public function testRuntimeBoots(): void
    {
        self::assertSame('UTF-8', mb_internal_encoding());
    }
}
