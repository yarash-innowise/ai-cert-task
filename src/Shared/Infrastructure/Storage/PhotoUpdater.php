<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Replaces a profile photo on its own, apart from the profile's text (design C11, edge "photo upload fails
 * midway"): validate and process the upload, store the files, then point the row at them and queue the
 * deletion of the old files in one transaction. When anything fails the old photo stays, the files stored by
 * the attempt are removed, and the caller shows the error while the text it saved earlier is kept.
 */
final class PhotoUpdater
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly Outbox $outbox,
        private readonly TransactionManager $transaction,
    ) {}

    /** True when the user actually chose a file (a browser sends an empty "no file" part otherwise). */
    public static function isChosen(?UploadedFileInterface $file): bool
    {
        return $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param callable(string): ?string $swap points the row at the new file name and returns the previous one
     * @throws ValidationFailed the file was refused or could not be stored (message names the field)
     */
    public function replace(UploadedFileInterface $file, callable $swap, string $field = 'photo'): void
    {
        $photo = $this->images->preparePhoto($file, $field);
        try {
            $name = $this->images->storePhoto($photo);
        } catch (StorageFailed) {
            throw ValidationFailed::forField($field, 'The photo could not be saved. Your previous photo is unchanged. Please try again.');
        }

        try {
            $this->transaction->run(function () use ($swap, $name): void {
                $this->images->deletePhotoAfterCommit($this->outbox, $swap($name));
            });
        } catch (\Throwable $e) {
            $this->images->discardPhoto($name);
            throw $e;
        }
    }
}
