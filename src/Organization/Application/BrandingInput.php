<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Shared\Domain\HexColor;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What the Trainer submitted on the branding form: the primary color, optionally a freshly uploaded
 * logo or the token of a logo staged by the no-JavaScript "Preview" step.
 */
final readonly class BrandingInput
{
    public function __construct(
        public string $colorText,
        public ?UploadedFileInterface $logo = null,
        public ?string $stagedLogo = null,
    ) {}
}
