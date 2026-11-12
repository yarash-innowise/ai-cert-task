<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Application\ImpersonationHistoryQuery;
use App\Admin\Application\ImpersonationHistoryRow;
use App\Admin\Domain\ImpersonationEndReason;
use DateTimeImmutable;
use PDO;

final class PdoImpersonationHistoryQuery implements ImpersonationHistoryQuery
{
    public function __construct(private readonly PDO $pdo) {}

    public function page(int $page): array
    {
        $count = $this->pdo->query('SELECT count(*) FROM impersonations');
        $total = $count === false ? 0 : (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.admin_user_id, admin.name AS admin_name, i.target_user_id, target.name AS target_name,
                    i.started_at, i.ended_at, i.end_reason,
                    CASE WHEN i.ended_at IS NULL THEN NULL ELSE EXTRACT(EPOCH FROM (i.ended_at - i.started_at))::bigint END AS duration_seconds,
                    (SELECT count(*) FROM audit_log a
                      WHERE a.action = 'impersonation.action' AND a.payload ->> 'impersonation_id' = i.id::text) AS action_count
               FROM impersonations i
               JOIN users admin ON admin.id = i.admin_user_id
               JOIN users target ON target.id = i.target_user_id
              ORDER BY i.started_at DESC, i.id
              LIMIT :limit OFFSET :offset",
        );
        $stmt->bindValue('limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = new ImpersonationHistoryRow(
                (string) $row['id'],
                (string) $row['admin_user_id'],
                (string) $row['admin_name'],
                (string) $row['target_user_id'],
                (string) $row['target_name'],
                new DateTimeImmutable((string) $row['started_at']),
                is_string($row['ended_at']) ? new DateTimeImmutable($row['ended_at']) : null,
                $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
                is_string($row['end_reason']) ? ImpersonationEndReason::from($row['end_reason']) : null,
                (int) $row['action_count'],
            );
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }
}
