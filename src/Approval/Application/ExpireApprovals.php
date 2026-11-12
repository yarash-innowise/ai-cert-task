<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A request with no parent action after 48 hours is automatically denied and both parent and child are
 * told (AC-44). One conditional UPDATE does the work, so running it twice, or while a parent decides, is
 * safe. Correctness never depends on this job running on time: DecideRequest checks the expiry itself (AC-45).
 */
final class ExpireApprovals
{
    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovalNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @return int how many requests expired now */
    public function execute(): int
    {
        return $this->transaction->run(function (): int {
            $expired = $this->requests->expireDue($this->clock->now());
            foreach ($expired as $request) {
                $this->notifier->toBoth($request);
                $this->audit->log('approval.expired', null, 'approval_request', $request->id, $request->organizationId, [
                    'request_id' => $request->id,
                    'kind' => $request->kind->value,
                ]);
            }

            return count($expired);
        });
    }
}
