<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\TenantMismatch;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\View;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps typed exceptions to responses; never leaks messages of unexpected errors or stack traces.
 *
 *  NotFound, TenantMismatch, unknown route  -> 404 (a foreign tenant's record looks like a missing one)
 *  Forbidden                                -> 403 page (generic text, no resource details)
 *  ValidationFailed                         -> 422 form re-render (or the generic 422 page)
 *  Conflict                                 -> 409 notice
 *  anything else                            -> 500 generic page, logged
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly View $view,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationFailed $e) {
            return $this->view->render($e->template ?? 'errors/422.twig', [
                'errors' => $e->errors,
                'old' => $e->old,
                'page_title' => 'Please check the form',
            ], 422);
        } catch (NotFound | TenantMismatch | NotFoundException) {
            return $this->view->render('errors/404.twig', ['page_title' => 'Page not found'], 404);
        } catch (MethodNotAllowedException) {
            return $this->view->render('errors/405.twig', ['page_title' => 'Method not allowed'], 405);
        } catch (Forbidden) {
            return $this->view->render('errors/403.twig', ['page_title' => 'Access denied'], 403);
        } catch (Conflict $e) {
            return $this->view->render('errors/409.twig', ['message' => $e->getMessage(), 'page_title' => 'Conflict'], 409);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->view->render('errors/500.twig', ['page_title' => 'Something went wrong'], 500);
        }
    }
}
