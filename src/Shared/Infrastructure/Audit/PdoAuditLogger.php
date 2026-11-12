<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use PDO;

final class PdoAuditLogger implements AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}

    public function log(
        string $action,
        ?string $actorUserId,
        string $subjectType,
        ?string $subjectId = null,
        ?string $organizationId = null,
        array $payload = [],
        ?string $impersonatorUserId = null,
        ?string $ip = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, impersonator_user_id, action, subject_type, subject_id, organization_id, payload, ip)
             VALUES (:actor, :imp, :action, :stype, :sid, :org, :payload, :ip)',
        );
        $stmt->execute([
            'actor' => $actorUserId,
            'imp' => $impersonatorUserId,
            'action' => $action,
            'stype' => $subjectType,
            'sid' => $subjectId,
            'org' => $organizationId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'ip' => $ip,
        ]);
    }
}
