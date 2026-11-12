<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Image;

use App\Shared\Http\Exception\ValidationFailed;

/**
 * Re-encodes every raster image with GD, which drops metadata (EXIF, ICC) and any payload appended to
 * or hidden in the original file, and scales it down (never up): logos to fit 200x200 (AC-84), photos
 * to 512 px plus a 128 px thumbnail (AC-71). SVG logos arrive already sanitized and are kept as they are.
 */
final class ImageProcessor
{
    public const LOGO_BOX = 200;
    public const PHOTO_MAX = 512;
    public const THUMB_MAX = 128;

    public function processLogo(ValidatedImage $image, string $field = 'logo'): ProcessedImage
    {
        if ($image->isSvg()) {
            return new ProcessedImage($image->bytes, 'svg', 'image/svg+xml', 0, 0);
        }

        return $this->reencode($image, self::LOGO_BOX, $field);
    }

    /** @return array{full: ProcessedImage, thumb: ProcessedImage} */
    public function processPhoto(ValidatedImage $image, string $field = 'photo'): array
    {
        return [
            'full' => $this->reencode($image, self::PHOTO_MAX, $field),
            'thumb' => $this->reencode($image, self::THUMB_MAX, $field),
        ];
    }

    private function reencode(ValidatedImage $image, int $box, string $field): ProcessedImage
    {
        $source = @imagecreatefromstring($image->bytes);
        if ($source === false) {
            throw ValidationFailed::forField($field, 'The file is damaged or is not a real image. Choose another file.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $box / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $png = $image->mime === 'image/png';
        $target = imagecreatetruecolor($newWidth, $newHeight);
        if ($png) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        } else {
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $written = $png ? imagepng($target, null, 6) : imagejpeg($target, null, 85);
        $bytes = (string) ob_get_clean();
        if (!$written || $bytes === '') {
            throw ValidationFailed::forField($field, 'The image could not be processed. Choose another file.');
        }

        return new ProcessedImage($bytes, $png ? 'png' : 'jpg', $image->mime, $newWidth, $newHeight);
    }
}
