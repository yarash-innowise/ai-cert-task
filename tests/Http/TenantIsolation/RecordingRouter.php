<?php

declare(strict_types=1);

namespace Tests\Http\TenantIsolation;

use League\Route\Route;
use League\Route\Router;
use Nyholm\Psr7\ServerRequest;

/** A router that remembers every route that gets registered, groups included, so a test can list them. */
final class RecordingRouter extends Router
{
    /** @var list<Route> */
    private array $recorded = [];

    /**
     * @param string|list<string> $method
     * @param callable|array<mixed>|string|\Psr\Http\Server\RequestHandlerInterface $handler
     */
    public function map(array|string $method, string $path, callable|array|string|\Psr\Http\Server\RequestHandlerInterface $handler): Route
    {
        $route = parent::map($method, $path, $handler);
        $this->recorded[] = $route;

        return $route;
    }

    /** @return list<string> "METHOD /path" for every registered route, sorted, trailing slashes removed */
    public function table(): array
    {
        // Group routes are only materialised when the router prepares its routes.
        $this->prepareRoutes(new ServerRequest('GET', 'https://app.test/'));

        $table = [];
        foreach ($this->recorded as $route) {
            $path = '/' . trim($route->getPath(), '/');
            $table[] = implode('|', (array) $route->getMethod()) . ' ' . $path;
        }
        sort($table);

        return array_values(array_unique($table));
    }
}
