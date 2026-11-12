<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\CurrentUser;
use App\Onboarding\Domain\Policy\ShareLinkPolicy;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/** Turns the player link off without replacing it; the old code immediately shows "link not valid" (AC-26). */
final class DeactivateLink
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly ShareLinkPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    public function execute(CurrentUser $actor, RequestContext $context, ?string $ip = null): void
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        $this->transaction->run(function () use ($actor, $organization, $ip): void {
            $link = $this->links->findActivePlayerLink($organization);
            if ($link === null || !$this->links->deactivatePlayerLink($organization, $this->clock->now())) {
                return;
            }
            $this->audit->log('share_link.deactivated', $actor->id, 'share_link', $link->id, $organization->value, ['share_link_id' => $link->id], null, $ip);
        });
    }
}
