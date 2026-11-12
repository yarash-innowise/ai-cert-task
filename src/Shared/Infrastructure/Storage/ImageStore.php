<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Image\ImageProcessor;
use App\Shared\Infrastructure\Image\ImageValidator;
use App\Shared\Infrastructure\Image\ProcessedImage;
use App\Shared\Infrastructure\Outbox\Outbox;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The upload pipeline shared by logos and photos: validate, process (pure, nothing written), then
 * store; plus the compensation (delete a just-stored file when the database change fails) and the
 * deferred deletion of a replaced file through the outbox, so an old file only disappears after the
 * change that replaced it has committed (design C5, C11).
 */
final class ImageStore
{
    public const DELETE_TOPIC = 'storage.delete';

    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageValidator $validator,
        private readonly ImageProcessor $processor,
    ) {}

    /** @throws ValidationFailed */
    public function prepareLogo(UploadedFileInterface $file): ProcessedImage
    {
        return $this->processor->processLogo($this->validator->validate($file, 'logo', allowSvg: true));
    }

    /**
     * @return array{full: ProcessedImage, thumb: ProcessedImage}
     * @throws ValidationFailed
     */
    public function preparePhoto(UploadedFileInterface $file, string $field = 'photo'): array
    {
        return $this->processor->processPhoto($this->validator->validate($file, $field), $field);
    }

    /** @throws StorageFailed */
    public function storeLogo(ProcessedImage $logo): string
    {
        return $this->storage->put(FileStorage::LOGOS, $logo->bytes, $logo->extension);
    }

    /**
     * Stores the photo and its thumbnail.
     *
     * @param array{full: ProcessedImage, thumb: ProcessedImage} $photo
     * @return string the name of the full-size file (the thumbnail name derives from it)
     * @throws StorageFailed
     */
    public function storePhoto(array $photo): string
    {
        $name = $this->storage->put(FileStorage::PHOTOS, $photo['full']->bytes, $photo['full']->extension);
        try {
            $this->storage->putAs(FileStorage::PHOTOS, self::thumbName($name), $photo['thumb']->bytes);
        } catch (StorageFailed $e) {
            $this->storage->delete(FileStorage::PHOTOS, $name);
            throw $e;
        }

        return $name;
    }

    /** Parks an already processed logo (the no-JavaScript "Preview" step) until the Trainer saves it. */
    public function stageLogo(ProcessedImage $logo): string
    {
        return $this->storage->put(FileStorage::TMP, $logo->bytes, $logo->extension);
    }

    /** The staged logo again, or null when the token is unknown or the file is gone. */
    public function staged(string $token): ?ProcessedImage
    {
        $bytes = $this->storage->get(FileStorage::TMP, $token);
        if ($bytes === null) {
            return null;
        }
        $extension = substr($token, (int) strrpos($token, '.') + 1);
        if ($extension === 'svg') {
            return new ProcessedImage($bytes, 'svg', 'image/svg+xml', 0, 0);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        return new ProcessedImage($bytes, $extension, $extension === 'png' ? 'image/png' : 'image/jpeg', $info[0], $info[1]);
    }

    public function dropStaged(string $token): void
    {
        $this->storage->delete(FileStorage::TMP, $token);
    }

    /** Immediate removal: only to undo a file stored by the request that is now failing. */
    public function discardLogo(string $name): void
    {
        $this->storage->delete(FileStorage::LOGOS, $name);
    }

    public function discardPhoto(string $name): void
    {
        $this->storage->delete(FileStorage::PHOTOS, $name);
        $this->storage->delete(FileStorage::PHOTOS, self::thumbName($name));
    }

    /** Queues the deletion of a replaced logo; call inside the transaction that replaces it. */
    public function deleteLogoAfterCommit(Outbox $outbox, ?string $name): void
    {
        if ($name !== null && $name !== '') {
            $outbox->enqueueJob(self::DELETE_TOPIC, ['kind' => FileStorage::LOGOS, 'names' => [$name]]);
        }
    }

    /** Queues the deletion of a replaced photo and its thumbnail; call inside the replacing transaction. */
    public function deletePhotoAfterCommit(Outbox $outbox, ?string $name): void
    {
        if ($name !== null && $name !== '') {
            $outbox->enqueueJob(self::DELETE_TOPIC, ['kind' => FileStorage::PHOTOS, 'names' => [$name, self::thumbName($name)]]);
        }
    }

    public static function thumbName(string $photoName): string
    {
        $dot = (int) strrpos($photoName, '.');

        return substr($photoName, 0, $dot) . '_thumb' . substr($photoName, $dot);
    }
}
