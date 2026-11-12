<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Mail\EmailMessage;
use DateTimeImmutable;
use PDO;

final class PdoOutbox implements Outbox
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function enqueueEmail(EmailMessage $message): string
    {
        return $this->insert('email', $message->template, [
            'to' => $message->to,
            'context' => $message->context,
        ], null);
    }

    public function enqueueJob(string $topic, array $payload = [], ?DateTimeImmutable $availableAt = null): string
    {
        return $this->insert('job', $topic, $payload, $availableAt);
    }

    /** @param array<string, mixed> $payload */
    private function insert(string $kind, string $topic, array $payload, ?DateTimeImmutable $availableAt): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO outbox (kind, topic, payload, available_at)
             VALUES (:kind, :topic, :payload, COALESCE(CAST(:available_at AS timestamptz), CAST(:now AS timestamptz)))
             RETURNING id',
        );
        $stmt->execute([
            'kind' => $kind,
            'topic' => $topic,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'available_at' => $availableAt?->format('Y-m-d H:i:sP'),
            'now' => $this->clock->now()->format('Y-m-d H:i:sP'),
        ]);

        return (string) $stmt->fetchColumn();
    }
}
