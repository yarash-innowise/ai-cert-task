<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\ResolvedShareLink;
use App\Onboarding\Domain\ShareLinkRepository;

/**
 * Turns a code from a URL or a form into a usable link. Unknown, deactivated, replaced links and
 * links of an inactive organization or a deactivated/deleted Trainer all give null: the caller
 * shows one generic "link not valid" message and writes nothing (AC-26).
 */
final class ResolveShareLink
{
    public function __construct(private readonly ShareLinkRepository $links) {}

    public function execute(string $code): ?ResolvedShareLink
    {
        return $this->links->findUsableByCode(trim($code));
    }
}
