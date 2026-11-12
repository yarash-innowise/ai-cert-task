<?php

declare(strict_types=1);

namespace App\Shared\Http;

use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the global middleware pipeline (outermost first) around the router.
 * Router exceptions (404/405) are thrown out of the router so the global
 * ErrorHandlerMiddleware turns them into pages.
 */
final class Kernel implements RequestHandlerInterface
{
    private ?Router $router = null;

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware global pipeline, outermost first
     * @param list<callable(Router, ContainerInterface): void> $routes route registrars
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $middleware,
        private readonly array $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->router();
        foreach (array_reverse($this->middleware) as $id) {
            $handler = $this->wrap($this->container->get($id), $handler);
        }

        return $handler->handle($request);
    }

    private function router(): Router
    {
        if ($this->router === null) {
            $strategy = new ApplicationStrategy();
            $strategy->setContainer($this->container);
            $router = new Router();
            $router->setStrategy($strategy);
            foreach ($this->routes as $register) {
                $register($router, $this->container);
            }
            $this->router = $router;
        }

        return $this->router;
    }

    private function wrap(mixed $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \LogicException('Pipeline entry is not a PSR-15 middleware.');
        }

        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
