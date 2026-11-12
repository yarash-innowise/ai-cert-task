<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Shared\Domain\HexColor;
use App\Shared\Domain\OrganizationId;

/** An organization's saved portal branding: an optional logo file and an optional primary color (null = default). */
final readonly class Branding
{
    public function __construct(
        public OrganizationId $organizationId,
        public string $businessName,
        public ?string $logoPath,
        public ?HexColor $color,
    ) {}

    public function palette(): BrandPalette
    {
        return $this->color === null ? BrandPalette::default() : BrandPalette::fromColor($this->color);
    }

    public function hasLogo(): bool
    {
        return $this->logoPath !== null;
    }
}
