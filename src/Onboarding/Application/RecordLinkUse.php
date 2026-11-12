<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\ShareLink;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\OrganizationId;

/** Records which link produced which association/registration, plus count and time (AC-23). Call inside the business transaction. */
final class RecordLinkUse
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly Clock $clock,
    ) {}

    public function execute(ShareLink $link, ?string $userId, ?string $playerProfileId, string $outcome): void
    {
        $this->links->recordUse(OrganizationId::fromString($link->organizationId), $link->id, $userId, $playerProfileId, $outcome, $this->clock->now());
    }
}
