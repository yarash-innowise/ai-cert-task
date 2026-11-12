<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\Shared\Domain\HexColor;

/**
 * The CSS custom properties derived from one primary color (design C5): the color itself, a soft tint,
 * a deep shade, its `r, g, b` triplet for translucent shadows, and the text color to put on top of it
 * (black or white, whichever has the higher WCAG contrast, so it is always at least 4.5:1).
 */
final readonly class BrandPalette
{
    public const DEFAULT_PRIMARY = '#006600';

    private function __construct(
        public string $primary,
        public string $soft,
        public string $deep,
        public string $rgb,
        public string $onBrand,
    ) {}

    public static function fromColor(HexColor $color): self
    {
        [$r, $g, $b] = self::channels($color->value);

        return new self(
            $color->value,
            self::hex(self::mix([$r, $g, $b], [255, 255, 255], 0.8)),
            self::hex([(int) round($r * 0.25), (int) round($g * 0.25), (int) round($b * 0.25)]),
            "{$r}, {$g}, {$b}",
            self::contrastRatio($color->value, '#FFFFFF') >= self::contrastRatio($color->value, '#000000') ? '#FFFFFF' : '#000000',
        );
    }

    /** The platform default accent (same values as `tokens.css`). */
    public static function default(): self
    {
        return self::fromColor(HexColor::fromString(self::DEFAULT_PRIMARY));
    }

    /** CSS declarations for a `:root` rule, safe to print because every value is computed from six hex digits. */
    public function css(): string
    {
        return sprintf(
            '--brand-primary: %s; --brand-primary-soft: %s; --brand-primary-deep: %s; --brand-primary-rgb: %s; --on-brand: %s;',
            $this->primary,
            $this->soft,
            $this->deep,
            $this->rgb,
            $this->onBrand,
        );
    }

    /** WCAG 2.x contrast ratio between two `#RRGGBB` colors (1.0 to 21.0). */
    public static function contrastRatio(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $linear = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** @return array{int, int, int} */
    private static function channels(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     * @return array{int, int, int}
     */
    private static function mix(array $from, array $to, float $amount): array
    {
        return [
            (int) round($from[0] + ($to[0] - $from[0]) * $amount),
            (int) round($from[1] + ($to[1] - $from[1]) * $amount),
            (int) round($from[2] + ($to[2] - $from[2]) * $amount),
        ];
    }

    /** @param array{int, int, int} $channels */
    private static function hex(array $channels): string
    {
        return sprintf('#%02X%02X%02X', $channels[0], $channels[1], $channels[2]);
    }
}
