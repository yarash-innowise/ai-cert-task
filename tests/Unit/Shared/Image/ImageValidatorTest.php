<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Image;

use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Image\ImageValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

#[Group('AC-71'), Group('AC-84')]
final class ImageValidatorTest extends TestCase
{
    private ImageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ImageValidator();
    }

    private function refusal(string $bytes, string $field = 'logo', bool $svg = true, int $error = UPLOAD_ERR_OK): ValidationFailed
    {
        try {
            $this->validator->validate(ImageFixtures::file($bytes, 'x.png', $error), $field, $svg);
        } catch (ValidationFailed $e) {
            return $e;
        }
        self::fail('The file should have been refused.');
    }

    public function testAcceptsPngJpegAndSvgForLogos(): void
    {
        self::assertSame('image/png', $this->validator->validate(ImageFixtures::file(ImageFixtures::png()), 'logo', true)->mime);
        self::assertSame('image/jpeg', $this->validator->validate(ImageFixtures::file(ImageFixtures::jpeg()), 'logo', true)->mime);
        self::assertTrue($this->validator->validate(ImageFixtures::file(ImageFixtures::svg()), 'logo', true)->isSvg());
    }

    public function testAcceptsAFileOfExactlyTwoMegabytes(): void
    {
        $image = $this->validator->validate(ImageFixtures::file(ImageFixtures::pngOfSize(2 * 1024 * 1024)), 'photo');

        self::assertSame('image/png', $image->mime);
    }

    public function testRefusesAFileOneByteOverTwoMegabytesNamingTheField(): void
    {
        $e = $this->refusal(ImageFixtures::pngOfSize(2 * 1024 * 1024 + 1), 'photo', false);

        self::assertSame(['photo'], array_keys($e->errors));
        self::assertStringContainsString('2 MB', $e->errors['photo']);
    }

    public function testRefusesAWrongTypeEvenWithAnImageFileName(): void
    {
        $e = $this->refusal('GIF89a' . str_repeat("\0", 40));

        self::assertStringContainsString('not allowed', $e->errors['logo']);
        self::assertStringContainsString('PNG, JPG or SVG', $e->errors['logo']);
    }

    public function testPhotosDoNotAcceptSvg(): void
    {
        $e = $this->refusal(ImageFixtures::svg(), 'photo', false);

        self::assertStringContainsString('PNG or JPG', $e->errors['photo']);
    }

    public function testRefusesACorruptedImage(): void
    {
        $e = $this->refusal(substr(ImageFixtures::png(), 0, 24));

        self::assertStringContainsString('damaged', $e->errors['logo']);
    }

    public function testRefusesAPolyglotThatPretendsToBeAnImage(): void
    {
        // PNG signature followed by markup: not a readable image, and never treated as SVG/HTML.
        $e = $this->refusal("\x89PNG\r\n\x1a\n<html><script>alert(1)</script></html>");

        self::assertArrayHasKey('logo', $e->errors);
    }

    public function testRefusesPhpAndHtmlRenamedAsImages(): void
    {
        self::assertArrayHasKey('logo', $this->refusal('<?php echo 1;')->errors);
        self::assertArrayHasKey('logo', $this->refusal('<html><body>hi</body></html>')->errors);
    }

    public function testRefusesAnEmptyFileAndFailedUploads(): void
    {
        self::assertArrayHasKey('logo', $this->refusal('')->errors);
        self::assertStringContainsString('Choose a file', $this->refusal('', 'logo', true, UPLOAD_ERR_NO_FILE)->errors['logo']);
        self::assertStringContainsString('2 MB', $this->refusal('', 'logo', true, UPLOAD_ERR_INI_SIZE)->errors['logo']);
        self::assertStringContainsString('did not finish', $this->refusal('', 'logo', true, UPLOAD_ERR_PARTIAL)->errors['logo']);
    }

    public function testRefusesImagesWithAbsurdDimensions(): void
    {
        $e = $this->refusal(self::hugeHeaderPng());

        self::assertArrayHasKey('logo', $e->errors);
    }

    /** A tiny file whose header claims 20,000 x 20,000 pixels (a decompression bomb). */
    private static function hugeHeaderPng(): string
    {
        $png = ImageFixtures::png(4, 4);
        $png = substr_replace($png, pack('NN', 20000, 20000), 16, 8);

        return $png;
    }
}
