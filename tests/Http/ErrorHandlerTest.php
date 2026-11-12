<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\TenantMismatch;
use App\Shared\Http\Exception\ValidationFailed;
use League\Route\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ErrorHandlerTest extends HttpTestCase
{
    private function throwingRoutes(): callable
    {
        return static function (Router $r): void {
            $r->get('/t/not-found', static fn() => throw new NotFound('secret-resource-name'));
            $r->get('/t/tenant', static fn() => throw new TenantMismatch());
            $r->get('/t/forbidden', static fn() => throw new Forbidden('user 4711 may not read invoice 99'));
            $r->get('/t/conflict', static fn() => throw new Conflict('The record changed, reload and try again.'));
            $r->get('/t/invalid', static fn() => throw ValidationFailed::forField('phone', 'Enter a valid phone number.'));
            $r->get('/t/invalid-form', static fn() => throw ValidationFailed::forField('name', 'Name is required.')->forForm('@test/form.twig', ['name' => 'Typed value']));
            $r->get('/t/boom', static fn() => throw new \RuntimeException('SQLSTATE[08006] password=hunter2 /var/www/secret.php'));
        };
    }

    /** @return iterable<string, array{string, int}> */
    public static function statusMap(): iterable
    {
        yield 'NotFound' => ['/t/not-found', 404];
        yield 'TenantMismatch looks like a missing page' => ['/t/tenant', 404];
        yield 'unknown route' => ['/no/such/page', 404];
        yield 'Forbidden' => ['/t/forbidden', 403];
        yield 'Conflict' => ['/t/conflict', 409];
        yield 'ValidationFailed' => ['/t/invalid', 422];
        yield 'unexpected error' => ['/t/boom', 500];
    }

    #[Group('AC-9')]
    #[DataProvider('statusMap')]
    public function testExceptionMapsToStatus(string $uri, int $status): void
    {
        $response = $this->request($this->kernel($this->throwingRoutes()), 'GET', $uri);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Group('AC-9')]
    public function testMethodNotAllowedIs405(): void
    {
        // POST needs a CSRF token to get past the global CSRF middleware and reach the router.
        $kernel = $this->kernel();
        $csrf = $this->guestCsrf($kernel);
        $request = (new \Nyholm\Psr7\ServerRequest('POST', '/health'))
            ->withCookieParams($csrf['cookies'])
            ->withParsedBody(['_csrf' => $csrf['token']]);
        self::assertSame(405, $kernel->handle($request)->getStatusCode());
    }

    #[Group('AC-9')]
    public function testForbiddenPageHidesDataAndTenantMismatchEqualsNotFound(): void
    {
        $kernel = $this->kernel($this->throwingRoutes());
        $forbidden = (string) $this->request($kernel, 'GET', '/t/forbidden')->getBody();
        self::assertStringNotContainsString('4711', $forbidden);
        self::assertStringNotContainsString('invoice', $forbidden);
        self::assertStringContainsString('Access denied', $forbidden);

        $notFound = (string) $this->request($kernel, 'GET', '/t/not-found')->getBody();
        self::assertStringNotContainsString('secret-resource-name', $notFound);

        $tenant = (string) $this->request($kernel, 'GET', '/t/tenant')->getBody();
        $missing = (string) $this->request($kernel, 'GET', '/no/such/page')->getBody();
        $normalize = static fn(string $html): string => (string) preg_replace('/nonce="[^"]*"/', '', $html);
        self::assertSame($normalize($missing), $normalize($tenant), 'foreign-tenant record must be indistinguishable from a missing page');
    }

    public function testUnexpectedErrorsNeverLeakDetailsOrStackTraces(): void
    {
        $body = (string) $this->request($this->kernel($this->throwingRoutes()), 'GET', '/t/boom')->getBody();
        foreach (['SQLSTATE', 'hunter2', 'secret.php', 'Stack trace', 'RuntimeException', '#0 '] as $needle) {
            self::assertStringNotContainsString($needle, $body);
        }
        self::assertStringContainsString('Something went wrong', $body);
    }

    #[Group('AC-89')]
    public function testValidationFailureRerendersFormWithSummaryAndOldValues(): void
    {
        $loader = $this->container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $loader);
        $fs = $loader->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $fs);
        $fs->addPath(__DIR__ . '/fixtures', 'test');

        $response = $this->request($this->kernel($this->throwingRoutes()), 'GET', '/t/invalid-form');
        $body = (string) $response->getBody();
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="error-summary"', $body);
        self::assertStringContainsString('href="#field-name"', $body);
        self::assertStringContainsString('aria-invalid="true"', $body);
        self::assertStringContainsString('aria-describedby="error-name"', $body);
        self::assertStringContainsString('value="Typed value"', $body);
    }

    #[Group('AC-89')]
    public function testGenericValidationPageNamesTheField(): void
    {
        $body = (string) $this->request($this->kernel($this->throwingRoutes()), 'GET', '/t/invalid')->getBody();
        self::assertStringContainsString('Enter a valid phone number.', $body);
        self::assertStringContainsString('href="#field-phone"', $body);
    }

    public function testConflictShowsItsNotice(): void
    {
        $body = (string) $this->request($this->kernel($this->throwingRoutes()), 'GET', '/t/conflict')->getBody();
        self::assertStringContainsString('The record changed, reload and try again.', $body);
    }

    #[Group('AC-89')]
    public function testPasswordFieldIsNeverEchoed(): void
    {
        $twig = $this->container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate("{% import 'components/form.twig' as f %}{{ f.field('password', 'Password', 'hunter2', null, 'password') }}")->render();
        self::assertStringNotContainsString('hunter2', $html);
    }
}
