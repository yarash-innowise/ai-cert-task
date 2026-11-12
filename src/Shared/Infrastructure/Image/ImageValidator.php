<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Image;

use App\Shared\Http\Exception\ValidationFailed;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Accepts an uploaded image only when it meets the agreed limits (Q-01.12, AC-71, AC-84): the upload
 * itself succeeded, at most 2 MB, a content-sniffed PNG or JPEG (SVG for logos only, sanitized), and a
 * readable image. The declared client type and file name are never trusted. Every refusal names the field.
 */
final class ImageValidator
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Guards GD's memory use against decompression bombs. */
    public const MAX_PIXELS = 25_000_000;

    private const RASTER = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG];

    public function __construct(private readonly SvgSanitizer $svg = new SvgSanitizer()) {}

    /**
     * @throws ValidationFailed
     */
    public function validate(UploadedFileInterface $file, string $field, bool $allowSvg = false): ValidatedImage
    {
        $allowed = $allowSvg ? 'PNG, JPG or SVG' : 'PNG or JPG';
        $this->assertUploaded($file, $field);

        $size = $file->getSize();
        if ($size === null || $size > self::MAX_BYTES) {
            throw ValidationFailed::forField($field, 'The file is larger than 2 MB. Choose a smaller file.');
        }
        $bytes = $this->read($file, $field);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationFailed::forField($field, 'The file is larger than 2 MB. Choose a smaller file.');
        }
        if ($bytes === '') {
            throw ValidationFailed::forField($field, 'The file is empty. Choose a ' . $allowed . ' image.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';

        if ($allowSvg && in_array($mime, ['image/svg+xml', 'text/xml', 'text/plain', 'application/xml'], true) && $this->looksLikeSvg($bytes)) {
            $clean = $this->svg->sanitize($bytes);
            if ($clean === null) {
                throw ValidationFailed::forField($field, 'The SVG contains scripts, external links or unsupported content. Export a plain SVG or use a PNG or JPG.');
            }

            return new ValidatedImage($clean, 'image/svg+xml', 0, 0);
        }

        if (!isset(self::RASTER[$mime])) {
            throw ValidationFailed::forField($field, 'This file type is not allowed. Upload a ' . $allowed . ' image.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== self::RASTER[$mime] || $info[0] < 1 || $info[1] < 1) {
            throw ValidationFailed::forField($field, 'The file is damaged or is not a real image. Choose another ' . $allowed . ' file.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationFailed::forField($field, 'The image has too many pixels. Choose a smaller image.');
        }

        return new ValidatedImage($bytes, $mime, $info[0], $info[1]);
    }

    private function assertUploaded(UploadedFileInterface $file, string $field): void
    {
        match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_NO_FILE => throw ValidationFailed::forField($field, 'Choose a file to upload.'),
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw ValidationFailed::forField($field, 'The file is larger than 2 MB. Choose a smaller file.'),
            default => throw ValidationFailed::forField($field, 'The upload did not finish. Please try again.'),
        };
    }

    private function read(UploadedFileInterface $file, string $field): string
    {
        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            return $stream->read(self::MAX_BYTES + 1);
        } catch (\RuntimeException) {
            throw ValidationFailed::forField($field, 'The upload did not finish. Please try again.');
        }
    }

    private function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/<svg[\s>]/i', substr($bytes, 0, 65536)) === 1;
    }
}
