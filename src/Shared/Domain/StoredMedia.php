<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** A stored file the viewer may fetch: where it lives (storage kind and name) and its content type. */
final readonly class StoredMedia
{
    public function __construct(
        public string $storageKind,
        public string $name,
    ) {}

    public function mime(): string
    {
        return match (substr($this->name, (int) strrpos($this->name, '.') + 1)) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    public function isSvg(): bool
    {
        return str_ends_with($this->name, '.svg');
    }
}
