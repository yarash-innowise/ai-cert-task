<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Domain\BrandPalette;
use App\Shared\Infrastructure\Image\ProcessedImage;

/** Staged, unsaved branding for the no-JavaScript "Preview" step: nothing of it is visible to anyone else. */
final readonly class BrandingPreview
{
    public function __construct(
        public BrandPalette $palette,
        public ?ProcessedImage $logo,
        public ?string $stagedLogo,
    ) {}

    /** The processed logo as a data URI for an `<img>` (allowed by the CSP), or null without a staged logo. */
    public function logoDataUri(): ?string
    {
        return $this->logo === null ? null : 'data:' . $this->logo->mime . ';base64,' . base64_encode($this->logo->bytes);
    }
}
