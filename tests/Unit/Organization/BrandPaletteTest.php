<?php

declare(strict_types=1);

namespace Tests\Unit\Organization;

use App\Organization\Domain\BrandPalette;
use App\Shared\Domain\HexColor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-82'), Group('AC-89')]
final class BrandPaletteTest extends TestCase
{
    public function testTheOnBrandTextMeetsFourPointFiveToOneForOneThousandRandomColors(): void
    {
        mt_srand(20261112);
        $worst = 21.0;
        for ($i = 0; $i < 1000; $i++) {
            $hex = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            $palette = BrandPalette::fromColor(HexColor::fromString($hex));

            $ratio = BrandPalette::contrastRatio($palette->onBrand, $palette->primary);
            self::assertGreaterThanOrEqual(4.5, $ratio, "{$hex} with {$palette->onBrand}");
            $worst = min($worst, $ratio);
        }
        self::assertGreaterThanOrEqual(4.5, $worst);
    }

    public function testTheTextColorFlipsBetweenLightAndDarkBackgrounds(): void
    {
        self::assertSame('#000000', BrandPalette::fromColor(HexColor::fromString('#FFFF00'))->onBrand);
        self::assertSame('#FFFFFF', BrandPalette::fromColor(HexColor::fromString('#001A33'))->onBrand);
        self::assertSame('#FFFFFF', BrandPalette::default()->onBrand);
    }

    public function testItDerivesTheFiveCustomProperties(): void
    {
        $palette = BrandPalette::fromColor(HexColor::fromString('#336699'));

        self::assertSame('#336699', $palette->primary);
        self::assertSame('51, 102, 153', $palette->rgb);
        self::assertSame('#0D1A26', $palette->deep);
        self::assertSame('#D6E0EB', $palette->soft);
        self::assertSame(
            '--brand-primary: #336699; --brand-primary-soft: #D6E0EB; --brand-primary-deep: #0D1A26; --brand-primary-rgb: 51, 102, 153; --on-brand: #FFFFFF;',
            $palette->css(),
        );
    }

    public function testTheDefaultMatchesTheDesignTokens(): void
    {
        $tokens = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/tokens.css');

        self::assertStringContainsString('--brand-primary: ' . BrandPalette::default()->primary, $tokens);
        self::assertStringContainsString('--brand-primary-rgb: ' . BrandPalette::default()->rgb, $tokens);
    }

    public function testContrastRatioKnownValues(): void
    {
        self::assertEqualsWithDelta(21.0, BrandPalette::contrastRatio('#000000', '#FFFFFF'), 0.001);
        self::assertEqualsWithDelta(1.0, BrandPalette::contrastRatio('#777777', '#777777'), 0.001);
    }
}
