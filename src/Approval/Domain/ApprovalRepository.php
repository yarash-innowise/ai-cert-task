<?php

declare(strict_types=1);

namespace App\Approval\Domain;

interface ApprovalRepository
{
    /** @throws OpenRequestExists the child already has an open request of this kind for this event; the caller's transaction is aborted */
    public function create(NewApprovalRequest $new): ApprovalRequest;

    /** The child's request of this kind for this event that is still open (pending, info requested or approved). */
    public function findOpen(string $childProfileId, string $eventRef, ApprovalKind $kind): ?ApprovalRequest;

    public function findById(string $id): ?ApprovalRequest;

    /** @return list<ApprovalRequest> the child login's own requests, newest first */
    public function forChild(string $childUserId): array;

    /** @return list<ApprovalRequest> the requests of the parent's children, open ones first, then newest first */
    public function forParent(string $parentUserId): array;

    /**
     * The parent's answer, in ONE conditional UPDATE that matches only the child's own parent, a request
     * that is still pending or info_requested, and one that has not expired by `$now` (AC-45). Zero rows
     * means refused: the caller reloads the request to say why. `approved` and `denied` end the request
     * (decided_at / decided_by set); `info_requested` keeps it open.
     *
     * @return ApprovalRequest|null the request after the change, or null when nothing matched
     */
    public function decide(string $id, string $parentUserId, ApprovalStatus $to, ?string $note, \DateTimeImmutable $now): ?ApprovalRequest;

    /**
     * Ends the open requests whose 48 hours are over (AC-44), in ONE conditional UPDATE, so it is safe to
     * run twice and while a parent decides: the parent's UPDATE or this one wins, never both.
     *
     * @return list<ApprovalRequest> the requests that expired now
     */
    public function expireDue(\DateTimeImmutable $now): array;

    /**
     * Denies every open request of a guardian who was deactivated or deleted.
     *
     * @return list<ApprovalRequest> the requests that were auto-denied
     */
    public function autoDenyForParent(string $parentUserId, \DateTimeImmutable $now): array;

    /**
     * Records the outcome of the payment hand-off (completed or payment_failed); only an `approved` request
     * moves, so a repeated job changes nothing.
     *
     * @return ApprovalRequest|null the request after the change, or null when it was not `approved`
     */
    public function finishHandoff(string $id, ApprovalStatus $to, \DateTimeImmutable $now): ?ApprovalRequest;
}
