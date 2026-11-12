<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TwigFactory
{
    public static function create(string $templatesPath, ?string $cachePath = null, bool $debug = false): Environment
    {
        return new Environment(new FilesystemLoader($templatesPath), [
            // HTML escaping everywhere except the plain-text email twins (*.text.twig).
            'autoescape' => static fn(string $name): string|false => str_ends_with($name, '.text.twig') ? false : 'html',
            'strict_variables' => true,
            'debug' => $debug,
            'cache' => $cachePath ?? false,
        ]);
    }
}
