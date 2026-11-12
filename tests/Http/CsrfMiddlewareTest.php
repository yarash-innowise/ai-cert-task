<?php

declare(strict_types=1);

namespace Tests\Http;

use League\Route\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Group('AC-11')]
final class CsrfMiddlewareTest extends AppHttpTestCase
{
    /** @return list<callable(Router, ContainerInterface): void> */
    protected function extraRoutes(): array
    {
        return [static function (Router $router): void {
            $factory = new Psr17Factory();
            $ok = static fn(ServerRequestInterface $r) => $factory->createResponse(200)->withBody($factory->createStream('done'));
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                $router->{$method}('/_t/change', $ok);
            }
        }];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('unsafeMethods')]
    public function testVisitorWithoutATokenIsRefused(string $method): void
    {
        $this->get('/health'); // gets the guest cookie, but sends no token
        self::assertSame(403, $this->send($method, '/_t/change', [])->getStatusCode());
    }

    #[DataProvider('unsafeMethods')]
    public function testWrongTokenIsRefused(string $method): void
    {
        $this->get('/health');
        self::assertSame(403, $this->send($method, '/_t/change', ['_csrf' => str_repeat('a', 43)])->getStatusCode());
    }

    #[DataProvider('unsafeMethods')]
    public function testValidTokenPasses(string $method): void
    {
        $token = $this->csrfToken();
        self::assertSame(200, $this->send($method, '/_t/change', ['_csrf' => $token])->getStatusCode());
    }

    public function testHeaderTokenIsAccepted(): void
    {
        $token = $this->csrfToken();
        self::assertSame(200, $this->send('POST', '/_t/change', [], ['X-CSRF-Token' => $token])->getStatusCode());
    }

    public function testSafeMethodsAreUnaffected(): void
    {
        self::assertSame(200, $this->get('/_t/change')->getStatusCode());
    }

    public function testForeignOriginIsRefusedEvenWithAValidToken(): void
    {
        $token = $this->csrfToken();

        self::assertSame(403, $this->send('POST', '/_t/change', ['_csrf' => $token], ['Origin' => 'https://evil.example'])->getStatusCode());
        self::assertSame(403, $this->send('POST', '/_t/change', ['_csrf' => $token], ['Origin' => 'null'])->getStatusCode());
        self::assertSame(403, $this->send('POST', '/_t/change', ['_csrf' => $token], ['Referer' => 'https://evil.example/page'])->getStatusCode());
        self::assertSame(200, $this->send('POST', '/_t/change', ['_csrf' => $token], ['Origin' => 'https://app.test'])->getStatusCode());
        self::assertSame(200, $this->send('POST', '/_t/change', ['_csrf' => $token], ['Referer' => 'https://app.test/form'])->getStatusCode());
    }

    public function testSignedInUsersUseTheTokenOfTheirSession(): void
    {
        $email = 'csrf@example.com';
        $this->createUser('player', $email);
        $this->login($email);
        $sessionToken = $this->csrfToken();

        self::assertSame(200, $this->send('POST', '/_t/change', ['_csrf' => $sessionToken])->getStatusCode());

        // The guest cookie token is not valid for a signed-in session.
        $guestToken = $this->guestCsrf($this->kernel)['token'];
        self::assertSame(403, $this->send('POST', '/_t/change', ['_csrf' => $guestToken])->getStatusCode());
    }

    public function testATokenFromAnotherSessionIsRefused(): void
    {
        $this->createUser('player', 'a@example.com');
        $this->createUser('player', 'b@example.com');
        $this->login('a@example.com');
        $tokenA = $this->csrfToken();
        $this->cookies = [];
        $this->login('b@example.com');

        self::assertSame(403, $this->send('POST', '/_t/change', ['_csrf' => $tokenA])->getStatusCode());
    }

    public function testFormsRenderTheTokenAndLogoutIsProtected(): void
    {
        $html = $this->body($this->get('/login'));
        self::assertMatchesRegularExpression('/<input type="hidden" name="_csrf" value="[A-Za-z0-9_-]{43}">/', $html);

        $this->createUser('player', 'out@example.com');
        $this->login('out@example.com');
        self::assertSame(403, $this->send('POST', '/logout', [])->getStatusCode(), 'logout without a token is refused');
        self::assertSame(1, $this->scalar('SELECT count(*) FROM sessions'));
    }

    public function testEveryPostRouteOfTheApplicationRejectsAMissingToken(): void
    {
        foreach (['/login', '/logout', '/forgot-password', '/reset-password/x', '/verify-email/resend', '/verify-email/x'] as $path) {
            self::assertSame(403, $this->send('POST', $path, [])->getStatusCode(), $path);
        }
    }
}
