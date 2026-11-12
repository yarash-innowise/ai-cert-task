<?php

declare(strict_types=1);

namespace App\Shared\Http\Exception;

final class Forbidden extends HttpException
{
    public function __construct(string $message = 'You do not have permission to do this.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
