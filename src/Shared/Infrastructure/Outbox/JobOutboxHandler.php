<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

/**
 * Dispatches `job` outbox rows to handlers registered per topic.
 */
final class JobOutboxHandler implements OutboxHandler
{
    /** @var array<string, callable(OutboxMessage): void> */
    private array $handlers = [];

    /** @param callable(OutboxMessage): void $handler */
    public function register(string $topic, callable $handler): void
    {
        $this->handlers[$topic] = $handler;
    }

    public function handle(OutboxMessage $message): void
    {
        $handler = $this->handlers[$message->topic] ?? null;
        if ($handler === null) {
            throw new \RuntimeException("No handler registered for job topic '{$message->topic}'.");
        }
        $handler($message);
    }
}
