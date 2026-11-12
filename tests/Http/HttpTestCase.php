<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Shared\Http\Kernel;
use League\Route\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Drives requests through the full middleware pipeline (error handling, security headers, ...).
 */
abstract class HttpTestCase extends TestCase
{
    protected ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $container = (require __DIR__ . '/../../config/container.php')();
        if ($container instanceof \DI\Container) {
            $container->set(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
        }
        $this->container = $container;
    }

    /**
     * @param callable(Router, ContainerInterface): void ...$extraRoutes registered after the application routes
     */
    protected function kernel(callable ...$extraRoutes): Kernel
    {
        /** @var list<class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware */
        $middleware = require __DIR__ . '/../../config/middleware.php';
        /** @var callable(Router, ContainerInterface): void $routes */
        $routes = require __DIR__ . '/../../config/routes.php';

        return new Kernel($this->container, $middleware, [$routes, ...array_values($extraRoutes)]);
    }

    /** @param array<string, string> $headers */
    protected function request(Kernel $kernel, string $method, string $uri, array $headers = []): ResponseInterface
    {
        return $kernel->handle(new ServerRequest($method, $uri, $headers));
    }

    /**
     * Fetches a CSRF token for a visitor without a session: the cookie value is the token.
     *
     * @return array{cookies: array<string, string>, token: string}
     */
    protected function guestCsrf(Kernel $kernel): array
    {
        $response = $this->request($kernel, 'GET', '/health');
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (preg_match('/^__Host-csrf=([^;]+)/', $line, $m) === 1) {
                return ['cookies' => ['__Host-csrf' => $m[1]], 'token' => $m[1]];
            }
        }
        self::fail('No CSRF cookie was issued.');
    }
}
