<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Renders Twig templates into PSR-7 responses.
 */
final class View
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($this->twig->render($template, $data));

        return $response;
    }
}
