<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Psr\Log\AbstractLogger;

/**
 * Minimal PSR-3 logger writing one JSON line per record to a stream (stderr by default).
 */
final class StreamLogger extends AbstractLogger
{
    /** @param resource|null $stream */
    public function __construct(private $stream = null) {}

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $stream = $this->stream ?? STDERR;
        fwrite($stream, json_encode([
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    }
}
