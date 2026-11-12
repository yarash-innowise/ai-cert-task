<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * When a parent (guardian) account is deactivated or deleted, every request still waiting for that
 * parent is denied automatically and the child (and the parent's in-app inbox) are told. The Admin
 * lifecycle (Q-01.14) calls this in the same transaction as the deactivation.
 */
final class AutoDenyForGuardian
{
    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovalNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @return int how many requests were auto-denied */
    public function execute(string $guardianUserId, ?string $actorUserId = null): int
    {
        return $this->transaction->run(function () use ($guardianUserId, $actorUserId): int {
            $denied = $this->requests->autoDenyForParent($guardianUserId, $this->clock->now());
            foreach ($denied as $request) {
                $this->notifier->toBoth($request);
                $this->audit->log('approval.auto_denied', $actorUserId, 'approval_request', $request->id, $request->organizationId, [
                    'request_id' => $request->id,
                    'kind' => $request->kind->value,
                    'guardian_user_id' => $guardianUserId,
                ]);
            }

            return count($denied);
        });
    }
}
