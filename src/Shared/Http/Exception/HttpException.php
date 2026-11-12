<?php

declare(strict_types=1);

namespace App\Shared\Http\Exception;

/**
 * Base of typed exceptions mapped to HTTP responses by ErrorHandlerMiddleware.
 */
abstract class HttpException extends \RuntimeException {}
