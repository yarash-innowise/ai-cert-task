<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Policy\OrganizationPolicy;
use App\Organization\Domain\OrganizationBrandings;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\ImageStore;

/** The Trainer returns the portal to the default logo and accent color (AC-82); the old logo file is removed after commit. */
final class ResetBranding
{
    public function __construct(
        private readonly OrganizationBrandings $brandings,
        private readonly OrganizationPolicy $policy,
        private readonly ImageStore $images,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    public function execute(CurrentUser $actor, RequestContext $context, ?string $ip = null): void
    {
        $organization = $context->organizationId ?? throw new NotFound();
        if (!$this->policy->canManage($actor, $context, $organization)) {
            throw new Forbidden();
        }

        $this->transaction->run(function () use ($actor, $organization, $ip): void {
            $current = $this->brandings->lock($organization) ?? throw new NotFound();
            $this->brandings->save($organization, null, null);
            $this->images->deleteLogoAfterCommit($this->outbox, $current->logoPath);
            $this->audit->log('branding.reset', $actor->id, 'organization', $organization->value, $organization->value, [], null, $ip);
        });
    }
}
