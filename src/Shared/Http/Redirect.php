<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class Redirect
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function to(string $location, int $status = 302): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Location', $location);
    }
}
