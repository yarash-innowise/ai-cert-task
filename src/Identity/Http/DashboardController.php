<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Role dashboard placeholders; later epics fill them. Access is gated by RequireRole on the route group. */
final class DashboardController
{
    public function __construct(private readonly View $view) {}

    public function admin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('admin/dashboard.twig', ['page_title' => 'Super Admin dashboard']);
    }

    public function trainer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('trainer/dashboard.twig', ['page_title' => 'Trainer dashboard']);
    }

    public function family(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('family/dashboard.twig', ['page_title' => 'Player and parent dashboard']);
    }
}
