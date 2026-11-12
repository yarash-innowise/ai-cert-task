<?php

declare(strict_types=1);

use App\Shared\Domain\MediaAccess;
use App\Shared\Infrastructure\Outbox\PurgeOutboxTask;
use App\Shared\Infrastructure\Storage\DeleteStoredFilesJob;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\ImageStore;
use App\Shared\Infrastructure\Storage\LocalFileStorage;
use App\Shared\Infrastructure\Storage\PdoMediaAccess;
use App\Shared\Infrastructure\Storage\PhotoUpdater;
use Psr\Container\ContainerInterface;

use function DI\add;
use function DI\autowire;
use function DI\factory;

/** Shared kernel bindings: file storage and the media authorization. */
return [
    FileStorage::class => factory(static fn(ContainerInterface $c): FileStorage => new LocalFileStorage(
        (string) ($c->get('settings')['storage']['path'] ?? dirname(__DIR__, 2) . '/var/storage'),
    )),
    MediaAccess::class => autowire(PdoMediaAccess::class),
    ImageStore::class => autowire(),
    PhotoUpdater::class => autowire(),
    // After a change that replaced a file commits, the old file is removed by the worker.
    'job.handlers' => add([[ImageStore::DELETE_TOPIC, DeleteStoredFilesJob::class]]),
    // Finished outbox rows are removed after a week (no original email is kept, Q-01.13).
    'scheduler.tasks' => add([PurgeOutboxTask::class]),
];
