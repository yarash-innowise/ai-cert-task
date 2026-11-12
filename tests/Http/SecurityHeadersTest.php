<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

final class SecurityHeadersTest extends HttpTestCase
{
    private function nonce(string $csp): string
    {
        $parts = explode("'nonce-", $csp);
        self::assertGreaterThan(1, count($parts), 'CSP must carry a script nonce');

        return strstr($parts[1], "'", true) ?: '';
    }

    #[Group('AC-89')]
    public function testCspNonceDiffersPerRequestAndMatchesTheMarkup(): void
    {
        $kernel = $this->kernel();
        $a = $this->request($kernel, 'GET', '/');
        $b = $this->request($kernel, 'GET', '/');
        $nonceA = $this->nonce($a->getHeaderLine('Content-Security-Policy'));
        $nonceB = $this->nonce($b->getHeaderLine('Content-Security-Policy'));

        self::assertNotSame($nonceA, $nonceB);
        self::assertStringContainsString('nonce="' . $nonceA . '"', (string) $a->getBody());
        self::assertStringNotContainsString('nonce="' . $nonceA . '"', (string) $b->getBody());
    }

    #[Group('AC-9')]
    public function testSecurityHeadersPresentOnEveryResponseIncludingErrors(): void
    {
        $kernel = $this->kernel();
        foreach (['/', '/health', '/missing-page'] as $uri) {
            $r = $this->request($kernel, 'GET', $uri);
            self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'), $uri);
            self::assertStringContainsString('max-age=', $r->getHeaderLine('Strict-Transport-Security'), $uri);
            self::assertSame('same-origin', $r->getHeaderLine('Referrer-Policy'), $uri);
            self::assertSame('DENY', $r->getHeaderLine('X-Frame-Options'), $uri);
            $csp = $r->getHeaderLine('Content-Security-Policy');
            foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'none'", "form-action 'self'", "base-uri 'self'"] as $directive) {
                self::assertStringContainsString($directive, $csp, $uri);
            }
            self::assertStringNotContainsString('unsafe-inline', $csp);
        }
    }

    public function testHandlerSetHeadersAreNotOverridden(): void
    {
        $kernel = $this->kernel(static function (\League\Route\Router $r): void {
            $r->get('/t/media', static function (): \Psr\Http\Message\ResponseInterface {
                return (new \Nyholm\Psr7\Response(200))->withHeader('Content-Security-Policy', "default-src 'none'");
            });
        });
        self::assertSame("default-src 'none'", $this->request($kernel, 'GET', '/t/media')->getHeaderLine('Content-Security-Policy'));
    }
}
