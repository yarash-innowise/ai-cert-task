<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notification;

use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Outbox\Outbox;
use PDO;

/**
 * Creates the in-app notification row and, optionally, queues the email through
 * the outbox. Call inside the business transaction.
 */
final class NotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Outbox $outbox,
    ) {}

    /** @param array<string, mixed> $payload */
    public function notify(
        string $userId,
        string $kind,
        string $title,
        string $body = '',
        array $payload = [],
        ?EmailMessage $email = null,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, kind, title, body, payload)
             VALUES (:user, :kind, :title, :body, :payload) RETURNING id',
        );
        $stmt->execute([
            'user' => $userId,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $id = (string) $stmt->fetchColumn();

        if ($email !== null) {
            $this->outbox->enqueueEmail($email);
        }

        return $id;
    }

    /**
     * Was a notification of this kind with these payload entries already created for the user within the
     * last `$withinSeconds` seconds? Used to notify at most once per window (AC-49). Database time.
     *
     * @param array<string, scalar> $match
     */
    public function existsWithin(string $userId, string $kind, array $match, int $withinSeconds): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM notifications
              WHERE user_id = :user AND kind = :kind AND payload @> CAST(:match AS jsonb)
                AND created_at > now() - make_interval(secs => :secs)
              LIMIT 1",
        );
        $stmt->bindValue('user', $userId);
        $stmt->bindValue('kind', $kind);
        $stmt->bindValue('match', json_encode($match, JSON_THROW_ON_ERROR));
        $stmt->bindValue('secs', $withinSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** Email only, for recipients without an account row (for example an invited coach). */
    public function email(EmailMessage $email): void
    {
        $this->outbox->enqueueEmail($email);
    }
}
