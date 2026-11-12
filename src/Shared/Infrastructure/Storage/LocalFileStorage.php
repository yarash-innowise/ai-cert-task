<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

/**
 * Stores files under `{root}/{logos|photos|tmp}` with random 128-bit names (never user-supplied),
 * written to a temporary name first and renamed so a half-written file is never served.
 */
final class LocalFileStorage implements FileStorage
{
    private const KINDS = [self::LOGOS, self::PHOTOS, self::TMP];
    private const EXTENSIONS = ['png', 'jpg', 'svg'];
    /** A name this storage generated: 32 hex characters, an optional `_thumb`, a known extension. */
    private const NAME = '/^[0-9a-f]{32}(?:_thumb)?\.(?:png|jpg|svg)$/';

    public function __construct(private readonly string $root) {}

    public function put(string $kind, string $contents, string $extension): string
    {
        $this->assertKind($kind);
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported stored file extension.');
        }
        $directory = $this->root . '/' . $kind;
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new StorageFailed('Storage directory could not be created.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $this->write($directory, $name, $contents);

        return $name;
    }

    public function putAs(string $kind, string $name, string $contents): void
    {
        $this->assertKind($kind);
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException('Invalid stored file name.');
        }
        $directory = $this->root . '/' . $kind;
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new StorageFailed('Storage directory could not be created.');
        }
        $this->write($directory, $name, $contents);
    }

    public function get(string $kind, string $name): ?string
    {
        $path = $this->path($kind, $name);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function delete(string $kind, string $name): void
    {
        $path = $this->path($kind, $name);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $kind, string $name): bool
    {
        $path = $this->path($kind, $name);

        return $path !== null && is_file($path);
    }

    private function write(string $directory, string $name, string $contents): void
    {
        $temporary = $directory . '/.' . $name . '.part';
        if (@file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);
            throw new StorageFailed('The file could not be written.');
        }
        if (!@rename($temporary, $directory . '/' . $name)) {
            @unlink($temporary);
            throw new StorageFailed('The file could not be stored.');
        }
    }

    private function path(string $kind, string $name): ?string
    {
        if (!in_array($kind, self::KINDS, true) || preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        return $this->root . '/' . $kind . '/' . $name;
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown storage kind.');
        }
    }
}
