<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Claims due outbox rows and handles them one by one.
 *
 * Claiming is a short transaction: due rows are locked with FOR UPDATE SKIP LOCKED and marked `processing`
 * with a lease (`locked_until`), then committed, so two workers never claim the same row. Each row is then
 * handled with NO transaction open around the handler (design C8/D12, AC-41; a handler that needs
 * atomicity opens its own), and its final status is written in its own single statement. A worker that
 * dies mid-batch loses only its unfinished rows: they become claimable again when the lease expires, and
 * rows already marked done are never handled twice. A failing handler is retried with backoff, up to
 * MAX_ATTEMPTS. Sent email rows are scrubbed (no address, names, links or context are kept, Q-01.13).
 */
final class OutboxWorker
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_BASE_SECONDS = 30;
    public const LEASE_SECONDS = 300;

    /** @param array<string, OutboxHandler> $handlers keyed by outbox kind */
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
        private readonly array $handlers,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return int number of rows claimed (processed or failed) */
    public function runOnce(int $batchSize = 20): int
    {
        $rows = $this->claim($batchSize);
        foreach ($rows as $row) {
            $this->process($row);
        }

        return count($rows);
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $batchSize): array
    {
        return $this->transactions->run(function () use ($batchSize): array {
            $now = $this->clock->now();
            $stmt = $this->pdo->prepare(
                "UPDATE outbox
                 SET status = 'processing',
                     locked_until = :until,
                     attempts = attempts + CASE WHEN status = 'processing' THEN 1 ELSE 0 END
                 WHERE id IN (
                     SELECT id FROM outbox
                     WHERE (status = 'pending' AND available_at <= :now)
                        OR (status = 'processing' AND locked_until <= :now)
                     ORDER BY available_at, id
                     LIMIT :limit
                     FOR UPDATE SKIP LOCKED
                 )
                 RETURNING id, kind, topic, payload, attempts, available_at",
            );
            $stmt->bindValue('until', $now->modify('+' . self::LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:sP'));
            $stmt->bindValue('now', $now->format('Y-m-d H:i:sP'));
            $stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll();
            usort($rows, static fn(array $a, array $b): int => [(string) $a['available_at'], (string) $a['id']] <=> [(string) $b['available_at'], (string) $b['id']]);

            return $rows;
        });
    }

    /** @param array<string, mixed> $row */
    private function process(array $row): void
    {
        $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
        $message = new OutboxMessage(
            (string) $row['id'],
            (string) $row['kind'],
            (string) $row['topic'],
            is_array($payload) ? $payload : [],
            (int) $row['attempts'],
        );

        try {
            if ($message->attempts >= self::MAX_ATTEMPTS) {
                // reclaimed after too many lost leases: a handler that keeps killing the worker is not run again
                throw new \RuntimeException('Lease expired too many times.');
            }
            $handler = $this->handlers[$message->kind] ?? throw new \RuntimeException("No handler for kind '{$message->kind}'.");
            $handler->handle($message);
        } catch (\Throwable $e) {
            $this->markFailure($message, $e);

            return;
        }

        $done = $this->pdo->prepare(
            "UPDATE outbox
             SET status = 'done', processed_at = :now, attempts = attempts + 1, last_error = NULL, locked_until = NULL,
                 payload = CASE WHEN kind = 'email' THEN '{}'::jsonb ELSE payload END
             WHERE id = :id AND status = 'processing'",
        );
        $done->execute(['now' => $this->clock->now()->format('Y-m-d H:i:sP'), 'id' => $message->id]);
    }

    private function markFailure(OutboxMessage $message, \Throwable $e): void
    {
        $attempts = $message->attempts + 1;
        $final = $attempts >= self::MAX_ATTEMPTS;
        $now = $this->clock->now();
        $next = $now->modify('+' . (self::BACKOFF_BASE_SECONDS * (2 ** min($attempts - 1, 20))) . ' seconds');
        // an email failure may quote the recipient: keep only the exception class for those rows
        $error = $message->kind === 'email' ? $e::class : $e->getMessage();
        $this->logger->warning('Outbox message failed', [
            'outbox_id' => $message->id,
            'topic' => $message->topic,
            'attempt' => $attempts,
            'final' => $final,
            'error' => $error,
        ]);

        $stmt = $this->pdo->prepare(
            "UPDATE outbox
             SET attempts = :attempts, status = :status, available_at = :next, last_error = :error, locked_until = NULL,
                 processed_at = CASE WHEN :final THEN CAST(:now AS timestamptz) ELSE processed_at END,
                 payload = CASE WHEN :final AND kind = 'email' THEN '{}'::jsonb ELSE payload END
             WHERE id = :id AND status = 'processing'",
        );
        $stmt->bindValue('attempts', $attempts, PDO::PARAM_INT);
        $stmt->bindValue('status', $final ? 'failed' : 'pending');
        $stmt->bindValue('next', $next->format('Y-m-d H:i:sP'));
        $stmt->bindValue('error', mb_substr($error, 0, 500));
        $stmt->bindValue('final', $final, PDO::PARAM_BOOL);
        $stmt->bindValue('now', $now->format('Y-m-d H:i:sP'));
        $stmt->bindValue('id', $message->id);
        $stmt->execute();
    }
}
