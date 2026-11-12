<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Image;

use App\Shared\Infrastructure\Image\ImageProcessor;
use App\Shared\Infrastructure\Image\ImageValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

#[Group('AC-71'), Group('AC-84')]
final class ImageProcessorTest extends TestCase
{
    private ImageValidator $validator;
    private ImageProcessor $processor;

    protected function setUp(): void
    {
        $this->validator = new ImageValidator();
        $this->processor = new ImageProcessor();
    }

    public function testAnOversizeLogoIsScaledToFitTwoHundredPixels(): void
    {
        $logo = $this->processor->processLogo($this->validator->validate(ImageFixtures::file(ImageFixtures::png(800, 400)), 'logo', true));

        self::assertSame(200, $logo->width);
        self::assertSame(100, $logo->height);
        $info = getimagesizefromstring($logo->bytes);
        self::assertIsArray($info);
        self::assertSame([200, 100], [$info[0], $info[1]]);
    }

    public function testASmallLogoIsNeverEnlarged(): void
    {
        $logo = $this->processor->processLogo($this->validator->validate(ImageFixtures::file(ImageFixtures::png(50, 40)), 'logo', true));

        self::assertSame([50, 40], [$logo->width, $logo->height]);
    }

    public function testASvgLogoKeepsItsVectorForm(): void
    {
        $logo = $this->processor->processLogo($this->validator->validate(ImageFixtures::file(ImageFixtures::svg()), 'logo', true));

        self::assertSame('svg', $logo->extension);
        self::assertStringContainsString('<svg', $logo->bytes);
    }

    public function testAPhotoGetsAFiveHundredTwelvePixelCopyAndAThumbnail(): void
    {
        $photo = $this->processor->processPhoto($this->validator->validate(ImageFixtures::file(ImageFixtures::jpeg(2000, 1000)), 'photo'));

        self::assertSame([512, 256], [$photo['full']->width, $photo['full']->height]);
        self::assertSame([128, 64], [$photo['thumb']->width, $photo['thumb']->height]);
        self::assertSame('jpg', $photo['full']->extension);
    }

    public function testPngTransparencyIsKept(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $out = $this->processor->processPhoto($this->validator->validate(ImageFixtures::file($png), 'photo'))['full'];
        $decoded = imagecreatefromstring($out->bytes);

        self::assertNotFalse($decoded);
        self::assertSame(127, (imagecolorat($decoded, 5, 5) >> 24) & 0x7F);
    }

    public function testMetadataAndAppendedPayloadsAreStripped(): void
    {
        $marker = 'SECRET-GPS-AND-PAYLOAD';
        $withComment = $this->processor->processPhoto($this->validator->validate(ImageFixtures::file(ImageFixtures::jpegWithComment($marker)), 'photo'))['full'];
        $withPhp = $this->processor->processPhoto($this->validator->validate(ImageFixtures::file(ImageFixtures::png() . '<?php system($_GET["c"]); ?>'), 'photo'))['full'];

        self::assertStringNotContainsString($marker, $withComment->bytes);
        self::assertStringNotContainsString('<?php', $withPhp->bytes);
        self::assertNotFalse(getimagesizefromstring($withPhp->bytes));
    }
}
