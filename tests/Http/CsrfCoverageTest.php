<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;
use Tests\Http\TenantIsolation\RecordingRouter;

/**
 * AC-11: every state-changing route of the application, found in the real route table, rejects a request that
 * carries no CSRF token, a wrong token, or a valid token sent from another origin. The check runs for a visitor
 * (double-submit cookie) and for a signed-in user (session token), so a route added later is covered automatically.
 */
#[Group('AC-11')]
final class CsrfCoverageTest extends AppHttpTestCase
{
    /** @return list<array{string, string}> method and concrete path of every non-GET route */
    private function unsafeRoutes(): array
    {
        $router = new RecordingRouter();
        $routes = require __DIR__ . '/../../config/routes.php';
        $routes($router, $this->container);

        $found = [];
        foreach ($router->table() as $entry) {
            [$methods, $path] = explode(' ', $entry, 2);
            foreach (explode('|', $methods) as $method) {
                if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $found[] = [$method, (string) preg_replace('/\{[^}]+\}/', '0190aaaa-0000-7000-8000-000000000001', $path)];
                }
            }
        }

        return $found;
    }

    public function testTheRouteTableHasStateChangingRoutes(): void
    {
        self::assertGreaterThan(40, count($this->unsafeRoutes()));
    }

    public function testEveryStateChangingRouteRejectsAVisitorWithoutAValidToken(): void
    {
        $guestToken = $this->guestToken();
        foreach ($this->unsafeRoutes() as [$method, $path]) {
            $this->assertRefused($method, $path, ['__Host-csrf' => $guestToken], $guestToken);
        }
    }

    public function testEveryStateChangingRouteRejectsASignedInUserWithoutAValidToken(): void
    {
        $this->signInAs('super_admin');
        $cookies = $this->cookies;
        $token = $this->csrfToken();
        foreach ($this->unsafeRoutes() as [$method, $path]) {
            $this->assertRefused($method, $path, $cookies, $token);
        }
    }

    /** @param array<string, string> $cookies */
    private function assertRefused(string $method, string $path, array $cookies, string $validToken): void
    {
        $label = "$method $path";
        $before = (int) $this->scalar('SELECT count(*) FROM audit_log');

        self::assertSame(403, $this->send($method, $path, [], [], $cookies)->getStatusCode(), "$label without a token");
        self::assertSame(403, $this->send($method, $path, ['_csrf' => 'not-the-token'], [], $cookies)->getStatusCode(), "$label with a wrong token");
        self::assertSame(403, $this->send($method, $path, ['_csrf' => $validToken], ['Origin' => 'https://evil.example'], $cookies)->getStatusCode(), "$label from another origin");
        self::assertSame(403, $this->send($method, $path, ['_csrf' => $validToken], ['Referer' => 'https://evil.example/page'], $cookies)->getStatusCode(), "$label from another referer");
        self::assertSame($before, (int) $this->scalar('SELECT count(*) FROM audit_log'), "$label changed something");
    }

    private function guestToken(): string
    {
        $this->cookies = [];
        $this->get('/login');

        return $this->cookie('__Host-csrf') ?? self::fail('no guest CSRF cookie was issued');
    }
}
