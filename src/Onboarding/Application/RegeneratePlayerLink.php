<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\CurrentUser;
use App\Onboarding\Domain\Policy\ShareLinkPolicy;
use App\Onboarding\Domain\ShareLink;
use App\Onboarding\Domain\ShareLinkCode;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/** Replaces the player link: the old code stops working at once, the new one starts (AC-18). */
final class RegeneratePlayerLink
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly ShareLinkPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    public function execute(CurrentUser $actor, RequestContext $context, ?string $ip = null): ShareLink
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $this->transaction->run(function () use ($actor, $organization, $ip): ShareLink {
            $now = $this->clock->now();
            $this->links->deactivatePlayerLink($organization, $now);
            $link = $this->links->createPlayerLink($organization, ShareLinkCode::generate(), $now);
            $this->audit->log('share_link.regenerated', $actor->id, 'share_link', $link->id, $organization->value, ['share_link_id' => $link->id], null, $ip);

            return $link;
        });
    }
}
