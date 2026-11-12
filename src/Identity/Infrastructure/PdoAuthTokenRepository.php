<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use DateTimeImmutable;
use PDO;

final class PdoAuthTokenRepository implements AuthTokenRepository
{
    private const TS = 'Y-m-d H:i:s.uP';

    public function __construct(private readonly PDO $pdo) {}

    public function issue(string $userId, AuthTokenPurpose $purpose, DateTimeImmutable $now): string
    {
        $this->revokeLive($userId, $purpose, $now);

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, created_at)
             VALUES (:u, :p, :h, :exp, :now)',
        );
        $stmt->execute([
            'u' => $userId,
            'p' => $purpose->value,
            'h' => hash('sha256', $token),
            'exp' => $now->add($purpose->lifetime())->format(self::TS),
            'now' => $now->format(self::TS),
        ]);

        return $token;
    }

    public function peek(string $token, AuthTokenPurpose $purpose, DateTimeImmutable $now): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM auth_tokens
             WHERE token_hash = :h AND purpose = :p AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
        );
        $stmt->execute(['h' => hash('sha256', $token), 'p' => $purpose->value, 'now' => $now->format(self::TS)]);
        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : null;
    }

    public function consume(string $token, AuthTokenPurpose $purpose, DateTimeImmutable $now): ?string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET consumed_at = :now
             WHERE token_hash = :h AND purpose = :p AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now
             RETURNING user_id',
        );
        $stmt->execute(['h' => hash('sha256', $token), 'p' => $purpose->value, 'now' => $now->format(self::TS)]);
        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : null;
    }

    public function revokeLive(string $userId, AuthTokenPurpose $purpose, DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_tokens SET revoked_at = :now
             WHERE user_id = :u AND purpose = :p AND consumed_at IS NULL AND revoked_at IS NULL',
        );
        $stmt->execute(['u' => $userId, 'p' => $purpose->value, 'now' => $now->format(self::TS)]);
    }

    public function purgeOlderThan(DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < :before');
        $stmt->execute(['before' => $before->format(self::TS)]);

        return $stmt->rowCount();
    }
}
