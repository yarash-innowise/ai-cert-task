<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Identity\Domain\ReturnTo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-9')]
final class ReturnToTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        yield 'protocol relative' => ['//evil.com'];
        yield 'protocol relative with path' => ['//evil.com/x'];
        yield 'absolute url' => ['https://evil.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'backslash trick' => ['/\\evil.com'];
        yield 'embedded backslash' => ['/a\\b'];
        yield 'no leading slash' => ['evil.com'];
        yield 'newline' => ["/ok\r\nLocation: https://evil.com"];
        yield 'tab' => ["/\tevil"];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['/x']];
        yield 'too long' => ['/' . str_repeat('a', 3000)];
        yield 'scheme in path' => ['/x:y@z'];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingThatCouldLeaveTheSite(mixed $value): void
    {
        $expected = $value === '/x:y@z' ? '/x:y@z' : null;
        self::assertSame($expected, ReturnTo::sanitize($value));
    }

    public function testAcceptsRelativePathsWithQuery(): void
    {
        self::assertSame('/family/children?x=1', ReturnTo::sanitize('/family/children?x=1'));
        self::assertSame('/', ReturnTo::sanitize('/'));
    }
}
