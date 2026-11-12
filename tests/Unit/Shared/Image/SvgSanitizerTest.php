<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Image;

use App\Shared\Infrastructure\Image\SvgSanitizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

#[Group('AC-81'), Group('AC-84')]
final class SvgSanitizerTest extends TestCase
{
    public function testAPlainSvgPasses(): void
    {
        self::assertNotNull((new SvgSanitizer())->sanitize(ImageFixtures::svg()));
    }

    public function testTheDefaultLogoPasses(): void
    {
        $svg = (string) file_get_contents(dirname(__DIR__, 4) . '/Task/designs/default_logo.svg');

        self::assertNotNull((new SvgSanitizer())->sanitize($svg));
    }

    public function testAScriptElementIsRefused(): void
    {
        self::assertNull((new SvgSanitizer())->sanitize(ImageFixtures::svg('<script>alert(1)</script>')));
    }

    public function testAnEventHandlerAttributeIsRefused(): void
    {
        self::assertNull((new SvgSanitizer())->sanitize(ImageFixtures::svg('<rect width="5" height="5" onload="alert(1)"/>')));
    }

    public function testARemoteReferenceIsRefused(): void
    {
        self::assertNull((new SvgSanitizer())->sanitize(ImageFixtures::svg('<image href="https://evil.example/x.png" width="5" height="5"/>')));
        self::assertNull((new SvgSanitizer())->sanitize(ImageFixtures::svg('<use xlink:href="https://evil.example/x.svg#a" xmlns:xlink="http://www.w3.org/1999/xlink"/>')));
    }

    public function testAForeignObjectWithHtmlIsRefused(): void
    {
        self::assertNull((new SvgSanitizer())->sanitize(ImageFixtures::svg('<foreignObject><iframe src="https://evil.example"></iframe></foreignObject>')));
    }

    public function testNonSvgMarkupIsRefused(): void
    {
        self::assertNull((new SvgSanitizer())->sanitize('<html><body>no</body></html>'));
        self::assertNull((new SvgSanitizer())->sanitize('not xml at all'));
    }
}
