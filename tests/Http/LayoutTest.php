<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

#[Group('AC-89')]
final class LayoutTest extends HttpTestCase
{
    public function testLayoutHasSkipLinkLandmarksStatusRegionAndSingleH1(): void
    {
        $html = (string) $this->request($this->kernel(), 'GET', '/')->getBody();

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<a class="skip-link" href="#main">', $html);
        self::assertStringContainsString('<main id="main"', $html);
        self::assertStringContainsString('role="banner"', $html);
        self::assertStringContainsString('role="contentinfo"', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('name="viewport"', $html);
    }

    public function testHealthEndpointAnswersOk(): void
    {
        $r = $this->request($this->kernel(), 'GET', '/health');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('ok', (string) $r->getBody());
    }

    public function testDesignTokensAndFocusStylesShip(): void
    {
        $tokens = (string) file_get_contents(__DIR__ . '/../../public/assets/css/tokens.css');
        $app = (string) file_get_contents(__DIR__ . '/../../public/assets/css/app.css');
        foreach (['--brand-primary:', '--brand-primary-soft:', '--brand-primary-deep:', '--brand-primary-rgb:', '--space-md: 16px', '--radius-sm: 10px'] as $token) {
            self::assertStringContainsString($token, $tokens);
        }
        self::assertStringContainsString(':focus-visible', $app);
    }
}
