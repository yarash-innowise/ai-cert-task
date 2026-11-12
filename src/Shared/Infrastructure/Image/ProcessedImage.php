<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Image;

/** An image ready to store: re-encoded raster, or sanitized SVG. */
final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public string $extension,
        public string $mime,
        public int $width,
        public int $height,
    ) {}
}
