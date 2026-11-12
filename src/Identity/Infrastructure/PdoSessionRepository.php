<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\Session;
use App\Identity\Domain\SessionPolicy;
use App\Identity\Domain\SessionRepository;
use DateInterval;
use DateTimeImmutable;
use PDO;

final class PdoSessionRepository implements SessionRepository
{
    private const TS = 'Y-m-d H:i:s.uP';

    public function __construct(private readonly PDO $pdo) {}

    public function find(string $tokenHash): ?Session
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token_hash = :h');
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $context = null;
        if (is_string($row['active_context'])) {
            $decoded = json_decode($row['active_context'], true);
            $context = is_array($decoded) ? $decoded : null;
        }

        return new Session(
            (string) $row['token_hash'],
            (string) $row['user_id'],
            (string) $row['csrf_token'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['last_seen_at']),
            $context,
            is_string($row['impersonation_id']) ? $row['impersonation_id'] : null,
            is_string($row['impersonator_user_id']) ? $row['impersonator_user_id'] : null,
        );
    }

    public function add(Session $session): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, last_seen_at, active_context, impersonation_id, impersonator_user_id)
             VALUES (:h, :u, :c, :created, :seen, :ctx, :imp, :impuser)',
        );
        $stmt->execute([
            'h' => $session->tokenHash,
            'u' => $session->userId,
            'c' => $session->csrfToken,
            'created' => $session->createdAt->format(self::TS),
            'seen' => $session->lastSeenAt->format(self::TS),
            'ctx' => $session->activeContext === null ? null : json_encode($session->activeContext, JSON_THROW_ON_ERROR),
            'imp' => $session->impersonationId,
            'impuser' => $session->impersonatorUserId,
        ]);
    }

    public function touch(string $tokenHash, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET last_seen_at = :at WHERE token_hash = :h');
        $stmt->execute(['at' => $at->format(self::TS), 'h' => $tokenHash]);
    }

    public function rotate(string $oldTokenHash, string $newTokenHash, string $newCsrfToken): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET token_hash = :new, csrf_token = :csrf WHERE token_hash = :old');
        $stmt->execute(['new' => $newTokenHash, 'csrf' => $newCsrfToken, 'old' => $oldTokenHash]);
    }

    public function setActiveContext(string $tokenHash, ?array $context): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET active_context = :ctx WHERE token_hash = :h');
        $stmt->execute(['ctx' => $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR), 'h' => $tokenHash]);
    }

    public function convert(string $tokenHash, string $userId, ?string $impersonationId, ?string $impersonatorUserId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET user_id = :u, impersonation_id = :imp, impersonator_user_id = :impuser, active_context = NULL WHERE token_hash = :h',
        );
        $stmt->execute(['u' => $userId, 'imp' => $impersonationId, 'impuser' => $impersonatorUserId, 'h' => $tokenHash]);
    }

    public function delete(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE token_hash = :h');
        $stmt->execute(['h' => $tokenHash]);
    }

    public function deleteAllForUser(string $userId, ?string $exceptTokenHash = null): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :u AND (CAST(:except AS text) IS NULL OR token_hash <> :except)');
        $stmt->execute(['u' => $userId, 'except' => $exceptTokenHash]);

        return $stmt->rowCount();
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_seen_at <= :idle OR created_at <= :abs');
        $stmt->execute([
            'idle' => $now->sub(new DateInterval(SessionPolicy::IDLE))->format(self::TS),
            'abs' => $now->sub(new DateInterval(SessionPolicy::ABSOLUTE))->format(self::TS),
        ]);

        return $stmt->rowCount();
    }
}
