<?php

declare(strict_types=1);

namespace App\Shared\Http\Exception;

final class Conflict extends HttpException
{
    public function __construct(string $message = 'The request conflicts with the current state.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
