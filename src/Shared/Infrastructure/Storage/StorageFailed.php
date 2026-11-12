<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

/** The storage could not write a file (disk full, permissions, ...). */
final class StorageFailed extends \RuntimeException {}
