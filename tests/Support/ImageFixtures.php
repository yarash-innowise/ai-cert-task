<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;

/** Small generated images and hostile files for the upload tests. */
final class ImageFixtures
{
    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function jpeg(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 30, 30, 200));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** A valid JPEG carrying a COM segment (metadata) with the marker text. */
    public static function jpegWithComment(string $comment): string
    {
        $jpeg = self::jpeg();

        return substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
    }

    public static function svg(string $inner = '<rect width="10" height="10" fill="#c00"/>'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="40" height="30" viewBox="0 0 40 30">' . $inner . '</svg>';
    }

    public static function file(string $bytes, string $name = 'upload.png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(Stream::create($error === UPLOAD_ERR_OK ? $bytes : ''), $error === UPLOAD_ERR_OK ? strlen($bytes) : 0, $error, $name, 'image/png');
    }

    /** A real PNG padded with trailing bytes up to an exact total size. */
    public static function pngOfSize(int $bytes): string
    {
        $png = self::png();

        return $png . str_repeat("\0", max(0, $bytes - strlen($png)));
    }
}
