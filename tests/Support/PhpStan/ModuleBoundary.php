<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan;

/**
 * Module boundary (design D17): code in `App\{Module}\...` may use another module's Domain,
 * Application and Http types, but never another module's `Infrastructure` (adapters are wired
 * only in config/container.d). `Shared` is the shared kernel: its Infrastructure may be used by all.
 */
final class ModuleBoundary
{
    /** @return string|null a message when `$namespace` may not import `$imported`, otherwise null */
    public static function violation(string $namespace, string $imported): ?string
    {
        $from = self::parts($namespace);
        $to = self::parts($imported);
        if ($from === null || $to === null) {
            return null;
        }
        [$fromModule] = $from;
        [$toModule, $toLayer] = $to;

        if ($toLayer !== 'Infrastructure' || $toModule === $fromModule || $toModule === 'Shared') {
            return null;
        }

        return sprintf('Module %s must not import %s: it is Infrastructure of module %s (depend on its Domain/Application interface instead).', $fromModule, ltrim($imported, '\\'), $toModule);
    }

    /** @return array{string, string}|null module and layer of an `App\Module\Layer\...` name */
    private static function parts(string $name): ?array
    {
        $segments = explode('\\', ltrim($name, '\\'));

        return count($segments) >= 3 && $segments[0] === 'App' ? [$segments[1], $segments[2]] : null;
    }
}
