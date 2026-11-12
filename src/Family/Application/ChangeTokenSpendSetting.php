<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * The parent turns "Allow token spending without approval" on or off for one child, at any time (AC-42).
 * Default OFF. Only the owning parent may (a child login is refused, a foreign profile is 404). A request
 * that is already pending keeps the rules it was snapshotted with; the new value applies to later requests (AC-43).
 */
final class ChangeTokenSpendSetting
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly PlayerProfilePolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws Forbidden the actor is a child login or not a Player/Parent account
     * @throws NotFound not the actor's child
     */
    public function execute(CurrentUser $actor, string $profileId, bool $enabled, ?string $ip = null): PlayerProfile
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);
        if ($profile === null || !$profile->isChild() || !$this->policy->canManage($actor, $profile)) {
            throw new NotFound();
        }

        $this->transaction->run(function () use ($actor, $profile, $enabled, $ip): void {
            if ($profile->tokenSpendWithoutApproval === $enabled) {
                return;
            }
            $this->profiles->setTokenSpendWithoutApproval($profile->id, $enabled);
            $this->audit->log('child.token_setting_changed', $actor->id, 'player_profile', $profile->id, null, [
                'profile_id' => $profile->id,
                'from' => $profile->tokenSpendWithoutApproval,
                'to' => $enabled,
            ], null, $ip);
        });

        return $this->profiles->findOwned($profile->id, $actor->id) ?? throw new NotFound();
    }
}
