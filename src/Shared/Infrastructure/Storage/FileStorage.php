<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

/**
 * Private file storage outside the web root (design C11). Files are addressed by a storage
 * kind and a generated name; callers never choose the name.
 */
interface FileStorage
{
    public const LOGOS = 'logos';
    public const PHOTOS = 'photos';
    public const TMP = 'tmp';

    /**
     * @param self::LOGOS|self::PHOTOS|self::TMP $kind
     * @param string $extension png, jpg or svg
     * @return string the generated file name (128-bit random hex plus extension)
     * @throws StorageFailed when the file cannot be written
     */
    public function put(string $kind, string $contents, string $extension): string;

    /**
     * Writes a sibling of an already generated name (for example the `_thumb` of a photo).
     *
     * @param self::LOGOS|self::PHOTOS|self::TMP $kind
     * @throws StorageFailed
     */
    public function putAs(string $kind, string $name, string $contents): void;

    /** @return string|null the contents, or null when the file does not exist or the name is not one this storage generated */
    public function get(string $kind, string $name): ?string;

    /** Removes the file; a missing file is not an error. */
    public function delete(string $kind, string $name): void;

    public function exists(string $kind, string $name): bool;
}
