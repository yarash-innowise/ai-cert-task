<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Image;

use enshrined\svgSanitize\Sanitizer;

/**
 * Wraps enshrined/svg-sanitize: scripts, event handlers and remote references are removed, and an
 * SVG that needed any cleaning at all is refused rather than silently altered (design C11).
 */
final class SvgSanitizer
{
    /** @return string|null the sanitized SVG, or null when it is not a clean, plain SVG document */
    public function sanitize(string $svg): ?string
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize($svg);
        if (!is_string($clean) || trim($clean) === '' || $sanitizer->getXmlIssues() !== []) {
            return null;
        }
        if (stripos($clean, '<svg') === false) {
            return null;
        }

        return $clean;
    }
}
