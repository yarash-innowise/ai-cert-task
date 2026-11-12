<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Storage\DeleteStoredFilesJob;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\LocalFileStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-71'), Group('AC-84')]
final class LocalFileStorageTest extends TestCase
{
    private string $root;
    private LocalFileStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pp-storage-test-' . bin2hex(random_bytes(6));
        $this->storage = new LocalFileStorage($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/{.,}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        foreach (glob($this->root . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    public function testStoresUnderARandomNameOutsideAnyUserInput(): void
    {
        $a = $this->storage->put(FileStorage::LOGOS, 'x', 'png');
        $b = $this->storage->put(FileStorage::LOGOS, 'y', 'png');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $a);
        self::assertNotSame($a, $b);
        self::assertSame('x', $this->storage->get(FileStorage::LOGOS, $a));
        self::assertSame([], glob($this->root . '/logos/.*.part') ?: [], 'no half-written files are left');
    }

    public function testNamesThatAreNotGeneratedOnesAreNeverResolved(): void
    {
        $this->storage->put(FileStorage::LOGOS, 'secret', 'png');

        foreach (['../logos/x.png', '../../etc/passwd', 'a.php', str_repeat('a', 32) . '.php', ''] as $bad) {
            self::assertNull($this->storage->get(FileStorage::LOGOS, $bad), $bad);
            self::assertFalse($this->storage->exists(FileStorage::LOGOS, $bad), $bad);
        }
        self::assertNull($this->storage->get('..', str_repeat('a', 32) . '.png'));
    }

    public function testRefusesUnknownKindsAndExtensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->put(FileStorage::LOGOS, 'x', 'php');
    }

    public function testDeleteRemovesTheFileAndIgnoresAMissingOne(): void
    {
        $name = $this->storage->put(FileStorage::TMP, 'x', 'jpg');
        $this->storage->delete(FileStorage::TMP, $name);
        $this->storage->delete(FileStorage::TMP, $name);

        self::assertFalse($this->storage->exists(FileStorage::TMP, $name));
    }

    public function testTheDeletionJobRemovesTheListedFiles(): void
    {
        $name = $this->storage->put(FileStorage::PHOTOS, 'x', 'png');
        $job = new DeleteStoredFilesJob($this->storage);

        $job(new OutboxMessage('1', 'job', 'storage.delete', ['kind' => FileStorage::PHOTOS, 'names' => [$name, str_repeat('b', 32) . '.png']], 0));

        self::assertFalse($this->storage->exists(FileStorage::PHOTOS, $name));
    }
}
