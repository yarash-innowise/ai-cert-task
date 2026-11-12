<?php

declare(strict_types=1);

namespace App\Shared\Http\Exception;

final class TenantMismatch extends HttpException
{
    public function __construct(string $message = 'Resource not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
