<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\Impersonation;
use App\Admin\Domain\ImpersonationEndReason;
use App\Admin\Domain\ImpersonationRepository;
use DateTimeImmutable;
use PDO;

final class PdoImpersonationRepository implements ImpersonationRepository
{
    private const TS = 'Y-m-d H:i:s.uP';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly PDO $pdo) {}

    public function start(string $adminUserId, string $targetUserId, DateTimeImmutable $at): Impersonation
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO impersonations (admin_user_id, target_user_id, started_at, last_activity_at)
             VALUES (:a, :t, :at, :at) RETURNING *',
        );
        $stmt->execute(['a' => $adminUserId, 't' => $targetUserId, 'at' => $at->format(self::TS)]);

        return $this->hydrate($this->row($stmt));
    }

    public function find(string $id): ?Impersonation
    {
        if (preg_match(self::UUID, $id) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM impersonations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function touch(string $id, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE impersonations SET last_activity_at = :at WHERE id = :id AND ended_at IS NULL AND last_activity_at < :at');
        $stmt->execute(['at' => $at->format(self::TS), 'id' => $id]);
    }

    public function end(string $id, DateTimeImmutable $endedAt, ImpersonationEndReason $reason): bool
    {
        // The end never precedes the start, whatever clock produced it.
        $stmt = $this->pdo->prepare(
            'UPDATE impersonations SET ended_at = GREATEST(:at, started_at), end_reason = :r WHERE id = :id AND ended_at IS NULL',
        );
        $stmt->execute(['at' => $endedAt->format(self::TS), 'r' => $reason->value, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function open(): array
    {
        $stmt = $this->pdo->query(
            'SELECT i.*, EXISTS (SELECT 1 FROM sessions s WHERE s.impersonation_id = i.id) AS session_alive
               FROM impersonations i WHERE i.ended_at IS NULL ORDER BY i.started_at',
        );
        $out = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $out[] = ['impersonation' => $this->hydrate($row), 'sessionAlive' => (bool) $row['session_alive']];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function row(\PDOStatement $stmt): array
    {
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Impersonation
    {
        return new Impersonation(
            (string) $row['id'],
            (string) $row['admin_user_id'],
            (string) $row['target_user_id'],
            new DateTimeImmutable((string) $row['started_at']),
            new DateTimeImmutable((string) $row['last_activity_at']),
            is_string($row['ended_at']) ? new DateTimeImmutable($row['ended_at']) : null,
            is_string($row['end_reason']) ? ImpersonationEndReason::from($row['end_reason']) : null,
        );
    }
}
