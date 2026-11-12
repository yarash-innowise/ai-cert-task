<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps every message, for tests that assert something was logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
