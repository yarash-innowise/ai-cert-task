<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\ApprovalStatus;
use App\Integration\Application\ApprovedPurchaseHandoff;
use App\Integration\Application\EventRegistrations;
use App\Integration\Application\HandoffResult;
use App\Integration\Application\RsvpCancellations;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use Psr\Log\LoggerInterface;

/**
 * Outbox job `approvals.handoff` (AC-41): an approved request is handed to the payment capability
 * (Epic-05) with no business transaction open around the call; the child is registered for the event
 * only when that capability reports success. Success -> `completed`; failure -> `payment_failed` and
 * the child is NOT registered; both are told. While the capability is not available (the Epic-01
 * stand-in answers "pending") the request stays `approved` and the call is logged, so the same job can
 * run again later. An RSVP has nothing to pay: its approval registers the child, and the approval of a
 * cancellation cancels that one RSVP, through the Epic-02 ports. The job is idempotent: only an
 * `approved` request is processed, and the request id is the payment capability's idempotency reference.
 */
final class HandOffApproved
{
    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovedPurchaseHandoff $purchases,
        private readonly EventRegistrations $registrations,
        private readonly RsvpCancellations $cancellations,
        private readonly ApprovalNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        $id = $message->payload['request_id'] ?? null;
        if (!is_string($id)) {
            throw new \InvalidArgumentException('Job payload has no request_id.');
        }
        $this->execute($id);
    }

    /** @return ApprovalStatus|null the status the request ended in, or null when nothing was finished (not approved, or still waiting for the capability) */
    public function execute(string $requestId): ?ApprovalStatus
    {
        $request = $this->requests->findById($requestId);
        if ($request === null || $request->status !== ApprovalStatus::Approved) {
            return null;
        }

        $outcome = $this->handOff($request);
        if ($outcome === null) {
            $this->logger->info('Approval hand-off left pending: payments not available', ['request' => $request->id, 'kind' => $request->kind->value]);

            return null;
        }

        return $this->transaction->run(function () use ($request, $outcome): ?ApprovalStatus {
            $finished = $this->requests->finishHandoff($request->id, $outcome, $this->clock->now());
            if ($finished === null) {
                return null; // another run finished it first
            }
            $this->notifier->toBoth($finished);
            $this->audit->log('approval.' . $finished->status->value, null, 'approval_request', $finished->id, $finished->organizationId, [
                'request_id' => $finished->id,
                'kind' => $finished->kind->value,
                'status' => $finished->status->value,
            ]);

            return $finished->status;
        });
    }

    /** @return ApprovalStatus|null Completed, PaymentFailed, or null while the capability has not answered */
    private function handOff(ApprovalRequest $request): ?ApprovalStatus
    {
        switch ($request->kind) {
            case ApprovalKind::Purchase:
            case ApprovalKind::TokenSpend:
                $tokens = $request->kind === ApprovalKind::TokenSpend ? $request->amountMinor : 0;
                $usd = $request->kind === ApprovalKind::Purchase;
                $result = $this->purchases->execute(
                    $request->id,
                    $request->parentUserId,
                    $request->childProfileId,
                    $request->eventRef,
                    $tokens,
                    $usd ? $request->amountMinor : null,
                    $usd ? $request->currency : null,
                );

                return match ($result) {
                    HandoffResult::Succeeded => ApprovalStatus::Completed,
                    HandoffResult::Failed => ApprovalStatus::PaymentFailed,
                    HandoffResult::Pending => null,
                };
            case ApprovalKind::Rsvp:
                return $this->registrations->register($request->eventRef, $request->childProfileId) ? ApprovalStatus::Completed : null;
            case ApprovalKind::RsvpCancel:
                return $this->cancellations->cancel($request->eventRef, $request->childProfileId) ? ApprovalStatus::Completed : null;
        }
    }
}
