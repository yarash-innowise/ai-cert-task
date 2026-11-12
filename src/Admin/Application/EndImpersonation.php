<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\Impersonation;
use App\Admin\Domain\ImpersonationEndReason;
use App\Admin\Domain\ImpersonationRepository;
use App\Identity\Application\SessionState;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use DateTimeImmutable;

/**
 * Ends the impersonation of the session (AC-53, AC-54, AC-55): the log row gets its end and reason, the audit
 * log gets the duration, and the session returns to the administrator's own view with a new identifier. Safe to
 * call for an impersonation the sweep has already closed.
 */
final class EndImpersonation
{
    public function __construct(
        private readonly ImpersonationRepository $impersonations,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    public function execute(SessionState $session, Impersonation $impersonation, ImpersonationEndReason $reason, ?DateTimeImmutable $endedAt = null, ?string $ip = null): void
    {
        $this->transaction->run(function () use ($session, $impersonation, $reason, $endedAt, $ip): void {
            $at = $endedAt ?? $this->clock->now();
            if ($this->impersonations->end($impersonation->id, $at, $reason)) {
                $stored = $this->impersonations->find($impersonation->id) ?? $impersonation;
                $ended = $stored->endedAt ?? $at;
                $this->audit->log('impersonation.ended', $impersonation->adminUserId, 'user', $impersonation->targetUserId, null, [
                    'impersonation_id' => $impersonation->id,
                    'target_user_id' => $impersonation->targetUserId,
                    'end_reason' => $reason->value,
                    'duration_seconds' => max(0, $ended->getTimestamp() - $impersonation->startedAt->getTimestamp()),
                ], null, $ip);
            }
            if ($session->session()?->isImpersonating() === true) {
                $session->endImpersonation();
            }
        });
    }
}
