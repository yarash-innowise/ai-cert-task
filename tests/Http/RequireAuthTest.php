<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Identity\Http\AuthenticationMiddleware;
use League\Route\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RequireAuthTest extends AppHttpTestCase
{
    /** @return list<callable(Router, ContainerInterface): void> */
    protected function extraRoutes(): array
    {
        return [static function (Router $router, ContainerInterface $c): void {
            $factory = new Psr17Factory();
            $auth = $c->get(\App\Identity\Http\RequireAuth::class);
            \assert($auth instanceof \App\Identity\Http\RequireAuth);
            $ok = static fn(ServerRequestInterface $r) => $factory->createResponse(200)->withBody($factory->createStream('secret page'));
            $router->get('/_t/page', $ok)->middleware($auth);
            $router->post('/_t/page', $ok)->middleware($auth);
            $verified = $c->get(\App\Identity\Http\RequireVerifiedEmail::class);
            \assert($verified instanceof \App\Identity\Http\RequireVerifiedEmail);
            $router->get('/_t/verified', $ok)->middleware($auth)->middleware($verified);
        }];
    }

    #[Group('AC-9')]
    public function testAnonymousGetIsSentToLoginWithAReturnPath(): void
    {
        $response = $this->get('/_t/page?tab=2');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?return_to=%2F_t%2Fpage%3Ftab%3D2', $this->location($response));
        self::assertStringNotContainsString('secret', $this->body($response));
    }

    #[Group('AC-8')]
    public function testSessionTimeoutWhileFillingAFormReturnsToThePageByGetWithoutReplayingThePost(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $csrf = $this->csrfToken();

        $this->clock->advance('8 days'); // idle timeout hits while the form is open
        $response = $this->send('POST', '/_t/page', ['_csrf' => $csrf, 'note' => 'half-typed text'], ['Referer' => 'https://app.test/_t/page?x=1']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?notice=expired&return_to=%2F_t%2Fpage%3Fx%3D1', $this->location($response));
        self::assertStringNotContainsString('secret', $this->body($response));

        // After signing in the browser follows a GET to the page; nothing from the POST ran.
        $this->cookies = [];
        $login = $this->post('/login', ['email' => 'who@example.com', 'password' => 'correct horse battery', 'return_to' => '/_t/page?x=1']);
        self::assertSame('/_t/page?x=1', $this->location($login));
        self::assertSame(200, $this->get('/_t/page?x=1')->getStatusCode());
    }

    #[Group('AC-8')]
    public function testExpiredSessionShowsTheSessionEndedNoticeOnTheLoginPage(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $this->clock->advance('31 days');

        $location = $this->location($this->get('/_t/page'));
        self::assertStringContainsString('notice=expired', $location);
        self::assertStringContainsString('Your session has ended', $this->body($this->get($location, [], [])));
    }

    #[Group('AC-9')]
    public function testPostWithoutLoginIsRedirectedAndItsBodyIsDiscarded(): void
    {
        $token = $this->csrfToken();
        $response = $this->send('POST', '/_t/page', ['_csrf' => $token, 'secret' => 'data']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $this->location($response), 'no Referer, so nothing to return to');
    }

    #[Group('AC-11')]
    public function testForeignRefererIsRefusedByCsrfAndNeverUsedAsAReturnPath(): void
    {
        $token = $this->csrfToken();
        $response = $this->send('POST', '/_t/page', ['_csrf' => $token], ['Referer' => 'https://evil.example/phish']);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull(\App\Identity\Domain\ReturnTo::fromReferer('https://evil.example/phish', 'app.test'));
    }

    #[Group('AC-11')]
    public function testExpiredSessionDoesNotTurnAForgedPostIntoAnythingButARedirect(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $this->clock->advance('8 days');

        $forged = $this->send('POST', '/_t/page', ['_csrf' => 'forged'], ['Origin' => 'https://evil.example']);
        self::assertSame(403, $forged->getStatusCode());
    }

    #[Group('AC-7')]
    public function testSignedInUserWithUnverifiedEmailIsSentToTheResendPage(): void
    {
        $id = $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $this->pdo->exec("UPDATE users SET email_verified_at = NULL WHERE id = '{$id}'");

        $response = $this->get('/_t/verified');
        self::assertSame('/verify-email/resend', $this->location($response));
    }

    #[Group('AC-15')]
    public function testUserWhoMustSetAPasswordCanOnlyReachThatPage(): void
    {
        $id = $this->createUser('trainer', 'trainer@example.com');
        $this->pdo->exec("UPDATE users SET must_set_password = true WHERE id = '{$id}'");
        $this->login('trainer@example.com');

        self::assertSame(AuthenticationMiddleware::SET_PASSWORD_PATH, $this->location($this->get('/trainer')));
        self::assertSame(AuthenticationMiddleware::SET_PASSWORD_PATH, $this->location($this->get('/')));
        self::assertSame(200, $this->get('/password/set')->getStatusCode());
    }
}
