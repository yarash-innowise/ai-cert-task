<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

final readonly class OutboxMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $kind,
        public string $topic,
        public array $payload,
        public int $attempts,
    ) {}
}
