<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\CurrentUser;
use App\Onboarding\Domain\ActiveLinkExists;
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

/** The Trainer's static player link (AC-18): created once, the same link is returned afterwards. */
final class GeneratePlayerLink
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly ShareLinkPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws NotFound the actor has no active organization @throws Forbidden not the Trainer of it */
    public function execute(CurrentUser $actor, RequestContext $context, ?string $ip = null): ShareLink
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        return $this->transaction->run(function () use ($actor, $organization, $ip): ShareLink {
            $existing = $this->links->findActivePlayerLink($organization);
            if ($existing !== null) {
                return $existing;
            }
            try {
                $link = $this->links->createPlayerLink($organization, ShareLinkCode::generate(), $this->clock->now());
            } catch (ActiveLinkExists) {
                // Lost a race with a concurrent request: the other request's link is the link.
                return $this->links->findActivePlayerLink($organization) ?? throw new \LogicException('Active link vanished.');
            }
            $this->audit->log('share_link.created', $actor->id, 'share_link', $link->id, $organization->value, ['share_link_id' => $link->id], null, $ip);

            return $link;
        });
    }
}
