<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\ImpersonationEndReason;
use App\Admin\Domain\ImpersonationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Closes impersonations nobody ended (AC-54, AC-55): one that ran past 1 hour ends as `timeout`, one whose session
 * is gone (expired or purged) ends as `session_expired`. The end is the earlier of "start + 1 hour" and the last
 * activity, so the log never claims time nobody used. A session that still points at a closed impersonation
 * returns to the administrator's own view at its next request (ImpersonationMiddleware).
 */
final class SweepImpersonations
{
    public function __construct(
        private readonly ImpersonationRepository $impersonations,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @return int how many impersonations were closed */
    public function execute(): int
    {
        $now = $this->clock->now();
        $closed = 0;
        foreach ($this->impersonations->open() as ['impersonation' => $impersonation, 'sessionAlive' => $alive]) {
            if ($impersonation->hasExpired($now)) {
                $reason = ImpersonationEndReason::Timeout;
            } elseif (!$alive) {
                $reason = ImpersonationEndReason::SessionExpired;
            } else {
                continue;
            }
            $endedAt = min($impersonation->expiresAt(), $impersonation->lastActivityAt);

            $this->transaction->run(function () use ($impersonation, $endedAt, $reason): void {
                if (!$this->impersonations->end($impersonation->id, $endedAt, $reason)) {
                    return;
                }
                $this->audit->log('impersonation.ended', $impersonation->adminUserId, 'user', $impersonation->targetUserId, null, [
                    'impersonation_id' => $impersonation->id,
                    'target_user_id' => $impersonation->targetUserId,
                    'end_reason' => $reason->value,
                    'duration_seconds' => max(0, $endedAt->getTimestamp() - $impersonation->startedAt->getTimestamp()),
                ]);
            });
            ++$closed;
        }

        return $closed;
    }
}
