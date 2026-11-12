<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\NewApprovalRequest;
use App\Approval\Domain\OpenRequestExists;
use App\Approval\Domain\PaymentType;
use App\Approval\Domain\Policy\ApprovalRequestPolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * The one place that opens a request (AC-38, AC-39, AC-48): a child login asks, nothing is completed,
 * a "Pending Parent Approval" request is recorded with child, event, amount, payment type, status and
 * timestamps, valid for 48 hours, the rules in force are snapshotted, and the parent is told by email
 * and in the app in the same transaction (outbox). The event, its title and (purchase) its price are resolved server-side from the
 * events catalog (F-11); an unknown event is refused. Asking again for the same thing while a request is
 * still open (a double click) returns the open request and notifies nobody twice.
 */
final class OpenApprovalRequest
{
    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovalRequestPolicy $policy,
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
        private readonly ResolveRequestedEvent $events,
    ) {}

    /**
     * @throws Forbidden the actor is not a child login (a parent, with or without children, never opens a request)
     * @throws NotFound the child has no active Trainer context
     * @throws Conflict the parent account cannot decide any more
     */
    public function execute(
        CurrentUser $actor,
        RequestContext $context,
        ApprovalKind $kind,
        PaymentType $paymentType,
        NewRequestInput $input,
        ?string $ip = null,
    ): ApprovalRequest {
        if (!$this->policy->canRequest($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findByLogin($actor->id) ?? throw new Forbidden();
        $organization = $context->organizationId ?? throw new NotFound();
        if ($this->associations->activeAssociation($profile->id, $organization->value) === null) {
            throw new NotFound();
        }
        $parent = $this->users->findById($profile->ownerUserId);
        if ($parent === null || !$parent->isActive()) {
            throw new Conflict('Your parent account is not active, so nobody can approve this right now.');
        }
        $businessName = $this->organizations->findById($organization->value)->businessName ?? '';

        // Title, price and currency come from the events catalog of this organization, never from the child's form (F-11)
        $event = $this->events->execute($organization, $input->eventRef);
        $amountMinor = match ($kind) {
            ApprovalKind::Purchase => $this->events->priceOf($event),
            ApprovalKind::TokenSpend => $input->amountMinor,
            default => 0,
        };
        $currency = $kind === ApprovalKind::Purchase ? $event->currency : 'USD';

        $existing = $this->requests->findOpen($profile->id, $input->eventRef, $kind);
        if ($existing !== null) {
            return $existing;
        }

        $now = $this->clock->now();
        $new = new NewApprovalRequest(
            $organization->value,
            $profile->id,
            $actor->id,
            $parent->id,
            $kind,
            $input->eventRef,
            $event->title,
            $amountMinor,
            $currency,
            $paymentType,
            [
                'kind' => $kind->value,
                'payment_type' => $paymentType->value,
                'token_spend_without_approval' => $profile->tokenSpendWithoutApproval,
            ],
            $input->childNote,
            $now,
            $now->add(new \DateInterval(ApprovalRequest::VALID_FOR)),
        );

        try {
            return $this->transaction->run(function () use ($new, $actor, $parent, $profile, $businessName, $ip): ApprovalRequest {
                $request = $this->requests->create($new);
                $this->notifyParent($request, $parent->id, $parent->email->value, $parent->name, $profile->fullName(), $businessName);
                $this->audit->log('approval.requested', $actor->id, 'approval_request', $request->id, $request->organizationId, [
                    'request_id' => $request->id,
                    'kind' => $request->kind->value,
                    'payment_type' => $request->paymentType->value,
                    'child_profile_id' => $request->childProfileId,
                    'trainer' => $businessName,
                ], null, $ip);

                return $request;
            });
        } catch (OpenRequestExists) {
            // Lost a race with the same child's other request: that one is the request.
            return $this->requests->findOpen($profile->id, $input->eventRef, $kind) ?? throw new Conflict('Please try again.');
        }
    }

    private function notifyParent(ApprovalRequest $request, string $parentId, string $parentEmail, string $parentName, string $childName, string $businessName): void
    {
        $what = $request->kind->label();
        $this->notifications->notify(
            $parentId,
            'approval_requested',
            sprintf('%s asks for your approval: %s', $childName, $what),
            sprintf('%s (%s%s). It expires in 48 hours.', $request->eventLabel(), $businessName, $request->amountLabel() === null ? '' : ', ' . $request->amountLabel()),
            ['request_id' => $request->id, 'organization_id' => $request->organizationId],
            new EmailMessage('approval-request', $parentEmail, [
                'parent_name' => $parentName,
                'child_name' => $childName,
                'business_name' => $businessName,
                'kind_label' => $what,
                'event_label' => $request->eventLabel(),
                'amount_label' => $request->amountLabel(),
                'payment_label' => $request->paymentType->label(),
                'child_note' => $request->childNote,
                'review_url' => $this->urls->absolute('/family/approvals/' . $request->id),
                'expires_at' => $request->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
            ]),
        );
    }
}
