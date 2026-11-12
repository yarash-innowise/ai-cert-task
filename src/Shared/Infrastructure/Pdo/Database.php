<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdo;

use PDO;

/**
 * Lazily opens the single PDO connection used by the request/worker.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {}

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }
}
