<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalJobs;
use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\Policy\ApprovalRequestPolicy;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * The child's own parent approves, denies, or asks for more information, with an optional note (AC-40).
 * The decision is ONE conditional UPDATE that matches only that parent, a request that is still
 * pending or info_requested, and one that has not expired (AC-45): a double click, two connections or
 * a late answer all match zero rows and are refused with the current status. Expiry is checked here
 * itself, so a decision after 48 hours is refused even when the expiry job did not run.
 * Approval queues the payment hand-off as an outbox job (it runs outside any transaction, AC-41).
 */
final class DecideRequest
{
    public const NOTE_MAX = 500;

    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovalRequestPolicy $policy,
        private readonly ApprovalNotifier $notifier,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws Forbidden the actor is not a parent account (for example a child login)
     * @throws NotFound no such request of the actor's children (another parent's request looks the same)
     * @throws Conflict the request was already decided, or expired
     */
    public function execute(CurrentUser $actor, string $requestId, Decision $decision, ?string $note = null, ?string $ip = null): ApprovalRequest
    {
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, self::NOTE_MAX);
        if ($actor->role !== Role::Player || $actor->isChildLogin()) {
            throw new Forbidden();
        }
        $found = $this->requests->findById($requestId);
        if ($found === null || !$this->policy->canDecide($actor, $found)) {
            throw new NotFound();
        }
        $now = $this->clock->now();

        return $this->transaction->run(function () use ($actor, $found, $decision, $note, $now, $ip): ApprovalRequest {
            $updated = $this->requests->decide($found->id, $actor->id, $decision->status(), $note, $now);
            if ($updated === null) {
                throw $this->refusal($found->id, $actor, $now);
            }
            if ($decision === Decision::Approve) {
                $this->outbox->enqueueJob(ApprovalJobs::HANDOFF, ['request_id' => $updated->id]);
            }
            $this->notifier->toChild($updated);
            $this->audit->log('approval.' . $updated->status->value, $actor->id, 'approval_request', $updated->id, $updated->organizationId, [
                'request_id' => $updated->id,
                'kind' => $updated->kind->value,
                'status' => $updated->status->value,
                'child_profile_id' => $updated->childProfileId,
            ], null, $ip);

            return $updated;
        });
    }

    /** Why the conditional UPDATE matched nothing: reload and say what is true now. */
    private function refusal(string $id, CurrentUser $actor, \DateTimeImmutable $now): \Throwable
    {
        $current = $this->requests->findById($id);
        if ($current === null || !$this->policy->canDecide($actor, $current)) {
            return new NotFound();
        }
        if (!$current->status->isDecidable()) {
            return new Conflict(sprintf('This request was already answered (%s), so it cannot be changed.', $current->status->parentLabel()));
        }

        return new Conflict('This request expired and is denied automatically. Nothing was bought or booked.');
    }
}
