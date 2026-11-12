<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\ApprovalStatus;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Http\AppUrl;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;

/**
 * Tells the child and the parent what happened to a request: an in-app notification plus the
 * "approval outcome" email (AC-39, AC-40, AC-44, AC-86). Call inside the transaction that changed the
 * request, so the notices exist only if the change does (D12).
 */
final class ApprovalNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PlayerProfileRepository $profiles,
        private readonly OrganizationRepository $organizations,
        private readonly NotificationService $notifications,
        private readonly AppUrl $urls,
    ) {}

    /** The child login is told (the parent made the decision, so the parent is not told about their own answer). */
    public function toChild(ApprovalRequest $request): void
    {
        $this->send($request, $request->childUserId, false);
    }

    public function toParent(ApprovalRequest $request): void
    {
        $this->send($request, $request->parentUserId, true);
    }

    /** Both the parent and the child (automatic denial, expiry, failed payment: AC-44). */
    public function toBoth(ApprovalRequest $request): void
    {
        $this->toParent($request);
        $this->toChild($request);
    }

    /** @return array{string, string} headline and detail sentence */
    public function words(ApprovalRequest $request, string $childName, bool $forParent): array
    {
        $what = $request->kind->label();
        $subject = $forParent ? $childName . "'s" : 'Your';

        return match ($request->status) {
            ApprovalStatus::Approved => [sprintf('%s %s was approved', $subject, $what), 'Your parent approved it. It is being completed now.'],
            ApprovalStatus::Completed => [sprintf('%s %s is confirmed', $subject, $what), 'Everything went through.'],
            ApprovalStatus::Denied => [sprintf('%s %s was denied', $subject, $what), 'It was denied, so nothing was bought or booked.'],
            ApprovalStatus::AutoDenied => [sprintf('%s %s was denied automatically', $subject, $what), 'The parent account is no longer active, so the request was denied and nothing was bought or booked.'],
            ApprovalStatus::InfoRequested => [sprintf('More information is needed for %s %s', $forParent ? $childName . "'s" : 'your', $what), 'Your parent asked for more information before deciding.'],
            ApprovalStatus::Expired => [sprintf('%s %s expired', $subject, $what), 'Nobody answered within 48 hours, so it was denied automatically. Nothing was bought or booked.'],
            ApprovalStatus::PaymentFailed => [sprintf('The payment for %s %s failed', $forParent ? $childName . "'s" : 'your', $what), 'It was approved, but the payment did not go through, so nothing was booked.'],
            ApprovalStatus::Pending => [sprintf('%s %s is waiting for approval', $subject, $what), 'It is waiting for the parent.'],
        };
    }

    private function send(ApprovalRequest $request, string $userId, bool $forParent): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return;
        }
        $profile = $this->profiles->findById($request->childProfileId);
        $childName = $profile?->fullName() ?? 'Your child';
        [$headline, $detail] = $this->words($request, $childName, $forParent);
        $businessName = $this->organizations->findById($request->organizationId)->businessName ?? '';
        $url = $this->urls->absolute($forParent ? '/family/approvals/' . $request->id : '/family/requests');
        $email = $user->isActive()
            ? new EmailMessage('approval-outcome', $user->email->value, [
                'recipient_name' => $user->name,
                'headline' => $headline,
                'detail' => $detail,
                'event_label' => $request->eventLabel(),
                'business_name' => $businessName,
                'amount_label' => $request->amountLabel(),
                'parent_note' => $request->parentNote,
                'view_url' => $url,
            ])
            : null;
        $this->notifications->notify(
            $userId,
            'approval_' . $request->status->value,
            $headline,
            $detail,
            ['request_id' => $request->id, 'status' => $request->status->value, 'organization_id' => $request->organizationId],
            $email,
        );
    }
}
