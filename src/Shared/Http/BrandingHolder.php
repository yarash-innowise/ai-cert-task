<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Organization\Domain\Branding;

/** The branding of the request's active organization, resolved once per request by {@see BrandingMiddleware}. */
final class BrandingHolder
{
    private ?Branding $branding = null;

    public function set(?Branding $branding): void
    {
        $this->branding = $branding;
    }

    public function get(): ?Branding
    {
        return $this->branding;
    }
}
