<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhpStan\ModuleBoundary;

final class ModuleBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'own infrastructure' => ['App\Admin\Application', 'App\Admin\Infrastructure\PdoUserDirectoryQuery', false];
        yield 'another module domain' => ['App\Admin\Application', 'App\Identity\Domain\User', false];
        yield 'another module application' => ['App\Admin\Application', 'App\Identity\Application\SessionState', false];
        yield 'shared kernel infrastructure' => ['App\Admin\Application', 'App\Shared\Infrastructure\Pdo\TransactionManager', false];
        yield 'external package' => ['App\Admin\Application', 'Psr\Log\LoggerInterface', false];
        yield 'another module infrastructure' => ['App\Admin\Application', 'App\Identity\Infrastructure\PdoUserRepository', true];
        yield 'shared importing a module infrastructure' => ['App\Shared\Http', 'App\Organization\Infrastructure\PdoOrganizationRepository', true];
        yield 'leading backslash' => ['App\Integration\Http', '\App\Identity\Infrastructure\PdoSessionRepository', true];
    }

    #[DataProvider('cases')]
    public function testRule(string $namespace, string $imported, bool $violates): void
    {
        self::assertSame($violates, ModuleBoundary::violation($namespace, $imported) !== null);
    }

    public function testNoSourceFileImportsAnotherModulesInfrastructure(): void
    {
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1) {
                continue;
            }
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^\s;{]+)/m', $code, $uses);
            foreach ($uses[1] as $imported) {
                $message = ModuleBoundary::violation($ns[1], $imported);
                if ($message !== null) {
                    $violations[] = $file->getFilename() . ': ' . $message;
                }
            }
        }

        self::assertSame([], $violations);
    }
}
