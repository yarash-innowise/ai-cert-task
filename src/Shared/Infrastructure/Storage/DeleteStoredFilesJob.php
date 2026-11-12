<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Infrastructure\Outbox\OutboxMessage;

/** Outbox job: removes files that a committed change replaced or orphaned (a missing file is fine). */
final class DeleteStoredFilesJob
{
    public function __construct(private readonly FileStorage $storage) {}

    public function __invoke(OutboxMessage $message): void
    {
        $kind = $message->payload['kind'] ?? null;
        $names = $message->payload['names'] ?? null;
        if (!is_string($kind) || !is_array($names)) {
            throw new \InvalidArgumentException('Job payload needs kind and names.');
        }
        foreach ($names as $name) {
            if (is_string($name)) {
                $this->storage->delete($kind, $name);
            }
        }
    }
}
