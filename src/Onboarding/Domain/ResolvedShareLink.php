<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** A usable link together with the Trainer's business name it leads to. */
final readonly class ResolvedShareLink
{
    public function __construct(public ShareLink $link, public string $businessName) {}
}
