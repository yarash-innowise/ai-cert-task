<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Onboarding\Domain\ResolvedShareLink;
use App\Shared\Http\AppUrl;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A logged-in child confirms the request to join a Trainer after opening the link (AC-49; the
 * POST, never the GET): nobody is associated and nothing about the
 * link use is recorded as a registration. The guardian is told by in-app notification and by
 * email "[Child] wants to join [Trainer]'s program" with a "Review Registration" button that
 * leads to the parent-only page `/family/join/{code}?child={profileId}`.
 */
final class BlockChildLink
{
    private const NOTIFY_WINDOW_SECONDS = 86400;

    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly AppUrl $urls,
    ) {}

    public function execute(CurrentUser $child, ResolvedShareLink $resolved, ?string $ip = null): void
    {
        $profile = $this->profiles->findByLogin($child->id);
        if ($profile === null) {
            return;
        }
        $parent = $this->users->findById($profile->ownerUserId);
        $link = $resolved->link;

        $this->transaction->run(function () use ($child, $profile, $parent, $resolved, $link, $ip): void {
            $this->audit->log('child.link_blocked', $child->id, 'player_profile', $profile->id, $link->organizationId, ['profile_id' => $profile->id, 'share_link_id' => $link->id], null, $ip);
            if ($parent === null || !$parent->isActive()) {
                return;
            }
            // At most one notification (and email) per child and Trainer within 24 hours (AC-49)
            $scope = ['profile_id' => $profile->id, 'organization_id' => $link->organizationId];
            if ($this->notifications->existsWithin($parent->id, 'child_wants_to_join', $scope, self::NOTIFY_WINDOW_SECONDS)) {
                return;
            }
            $title = sprintf("%s wants to join %s's program", $profile->fullName(), $resolved->businessName);
            $reviewUrl = $this->urls->absolute('/family/join/' . rawurlencode($link->code) . '?child=' . rawurlencode($profile->id));
            $this->notifications->notify(
                $parent->id,
                'child_wants_to_join',
                $title,
                'Open the registration request to review it.',
                ['profile_id' => $profile->id, 'organization_id' => $link->organizationId, 'review_url' => $reviewUrl],
                new EmailMessage('child-wants-to-join', $parent->email->value, [
                    'parent_name' => $parent->name,
                    'child_name' => $profile->fullName(),
                    'business_name' => $resolved->businessName,
                    'review_url' => $reviewUrl,
                ]),
            );
        });
    }
}
