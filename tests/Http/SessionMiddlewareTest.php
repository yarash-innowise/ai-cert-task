<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Identity\Application\SessionState;
use App\Identity\Domain\Session;
use App\Identity\Infrastructure\PurgeSessionsTask;
use League\Route\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Group('AC-8')]
final class SessionMiddlewareTest extends AppHttpTestCase
{
    /** @return list<callable(Router, ContainerInterface): void> */
    protected function extraRoutes(): array
    {
        return [static function (Router $router): void {
            $factory = new Psr17Factory();
            $state = static function (ServerRequestInterface $r): SessionState {
                $s = $r->getAttribute('session_state');
                \assert($s instanceof SessionState);

                return $s;
            };
            $text = static fn(string $body) => $factory->createResponse(200)->withBody($factory->createStream($body));

            $router->get('/_t/whoami', static fn(ServerRequestInterface $r) => $text($state($r)->userId() ?? 'anonymous'));
            $router->post('/_t/start/{id}', static function (ServerRequestInterface $r, array $args) use ($state, $text) {
                $state($r)->start((string) $args['id']);

                return $text('started');
            });
            $router->post('/_t/regenerate', static function (ServerRequestInterface $r) use ($state, $text) {
                $state($r)->regenerate();

                return $text('regenerated');
            });
            $router->post('/_t/end', static function (ServerRequestInterface $r) use ($state, $text) {
                $state($r)->end();

                return $text('ended');
            });
        }];
    }

    private function signIn(): string
    {
        $userId = $this->createUser();
        $response = $this->post('/_t/start/' . $userId);
        self::assertSame(200, $response->getStatusCode());

        return $userId;
    }

    public function testCookieIsHostPrefixedSecureHttpOnlySameSiteLax(): void
    {
        $userId = $this->createUser();
        $cookie = $this->post('/_t/start/' . $userId)->getHeader('Set-Cookie');
        $line = implode("\n", array_filter($cookie, static fn(string $c) => str_starts_with($c, '__Host-sid=')));

        self::assertStringStartsWith('__Host-sid=', $line);
        self::assertStringContainsString('Secure', $line);
        self::assertStringContainsString('HttpOnly', $line);
        self::assertStringContainsString('SameSite=Lax', $line);
        self::assertStringContainsString('Path=/', $line);
        self::assertStringNotContainsString('Domain', $line);
    }

    public function testOnlyTheSha256OfTheCookieIsStored(): void
    {
        $this->signIn();
        $token = $this->cookies['__Host-sid'];

        self::assertSame(1, $this->scalar('SELECT count(*) FROM sessions WHERE token_hash = :h', ['h' => hash('sha256', $token)]));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions WHERE token_hash = :h', ['h' => $token]));
    }

    public function testSessionResolvesTheUserOnLaterRequests(): void
    {
        $userId = $this->signIn();

        self::assertSame($userId, $this->body($this->get('/_t/whoami')));
        self::assertSame('anonymous', $this->body($this->get('/_t/whoami', [], [])));
    }

    public function testIdleSessionExpiresAfterSevenDays(): void
    {
        $this->signIn();

        $this->clock->advance('6 days 23 hours');
        self::assertNotSame('anonymous', $this->body($this->get('/_t/whoami')), 'still valid just under 7 days idle');

        $this->clock->advance('7 days 1 second');
        $response = $this->get('/_t/whoami');
        self::assertSame('anonymous', $this->body($response));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'), 'expired row is deleted');
        self::assertStringContainsString('Max-Age=0', implode(';', $response->getHeader('Set-Cookie')));
    }

    public function testAbsoluteLifetimeEndsAnActiveSessionAfterThirtyDays(): void
    {
        $this->signIn();

        for ($day = 1; $day <= 29; ++$day) {
            $this->clock->advance('1 day');
            self::assertNotSame('anonymous', $this->body($this->get('/_t/whoami')), "active on day {$day}");
        }
        $this->clock->advance('1 day 1 second');

        self::assertSame('anonymous', $this->body($this->get('/_t/whoami')), 'open longer than 30 days requires a fresh login');
    }

    public function testExpiredSessionRequiresFreshLoginAndOldCookieStaysDead(): void
    {
        $userId = $this->signIn();
        $oldCookie = $this->cookies;

        $this->clock->advance('8 days');
        $this->get('/_t/whoami');
        self::assertSame('anonymous', $this->body($this->get('/_t/whoami', [], $oldCookie)), 'the old cookie no longer works');

        // a fresh login works again
        $this->cookies = [];
        $this->post('/_t/start/' . $userId);
        self::assertSame($userId, $this->body($this->get('/_t/whoami')));
    }

    public function testRegenerationChangesTheIdAndKeepsTheUser(): void
    {
        $userId = $this->signIn();
        $before = $this->cookies['__Host-sid'];
        $csrfBefore = $this->scalar('SELECT csrf_token FROM sessions');

        $this->post('/_t/regenerate');
        $after = $this->cookies['__Host-sid'];

        self::assertNotSame($before, $after);
        self::assertSame($userId, $this->body($this->get('/_t/whoami')));
        self::assertSame('anonymous', $this->body($this->get('/_t/whoami', [], ['__Host-sid' => $before])), 'the old id is dead');
        self::assertNotSame($csrfBefore, $this->scalar('SELECT csrf_token FROM sessions'), 'CSRF token rotates with the id');
        self::assertSame(1, $this->scalar('SELECT count(*) FROM sessions'));
    }

    public function testEndingTheSessionDeletesTheRowAndClearsTheCookie(): void
    {
        $this->signIn();
        $response = $this->post('/_t/end');

        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
        self::assertStringContainsString('Max-Age=0', implode(';', $response->getHeader('Set-Cookie')));
        self::assertArrayNotHasKey('__Host-sid', $this->cookies);
    }

    public function testLastSeenIsTouchedAtMostOncePerMinute(): void
    {
        $this->signIn();
        $initial = $this->scalar('SELECT last_seen_at FROM sessions');

        $this->clock->advance('30 seconds');
        $this->get('/_t/whoami');
        self::assertSame($initial, $this->scalar('SELECT last_seen_at FROM sessions'), 'no write inside the minute');

        $this->clock->advance('31 seconds');
        $this->get('/_t/whoami');
        self::assertNotSame($initial, $this->scalar('SELECT last_seen_at FROM sessions'), 'written after a minute');
    }

    public function testPurgeTaskRemovesExpiredSessionsOnly(): void
    {
        $fresh = $this->createUser();
        $stale = $this->createUser();
        $repo = $this->container->get(\App\Identity\Domain\SessionRepository::class);
        \assert($repo instanceof \App\Identity\Domain\SessionRepository);
        $now = $this->clock->now();
        $repo->add(new Session(hash('sha256', 'a'), $fresh, 'c1', $now, $now));
        $repo->add(new Session(hash('sha256', 'b'), $stale, 'c2', $now->modify('-9 days'), $now->modify('-8 days')));

        $task = $this->container->get(PurgeSessionsTask::class);
        \assert($task instanceof PurgeSessionsTask);
        $task->run();

        self::assertSame(1, $this->scalar('SELECT count(*) FROM sessions'));
        self::assertSame(hash('sha256', 'a'), $this->scalar('SELECT token_hash FROM sessions'));
    }
}
