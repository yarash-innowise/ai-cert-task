<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxHandler
{
    /** @throws \Throwable on failure; the worker retries with backoff */
    public function handle(OutboxMessage $message): void;
}
