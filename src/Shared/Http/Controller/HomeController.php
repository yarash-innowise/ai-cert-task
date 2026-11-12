<?php

declare(strict_types=1);

namespace App\Shared\Http\Controller;

use App\Shared\Http\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('home/index.twig', ['page_title' => 'Welcome']);
    }

    public function health(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(200)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write('ok');

        return $response;
    }
}
