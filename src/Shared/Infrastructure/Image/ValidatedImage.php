<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Image;

/** An uploaded image that passed {@see ImageValidator}: bytes (SVG already sanitized) and the detected type. */
final readonly class ValidatedImage
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public int $width,
        public int $height,
    ) {}

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml';
    }
}
