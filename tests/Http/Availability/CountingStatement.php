<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

/** Counts executed statements on a connection (set through PDO::ATTR_STATEMENT_CLASS). */
final class CountingStatement extends \PDOStatement
{
    public static int $executed = 0;

    protected function __construct() {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        ++self::$executed;

        return parent::execute($params);
    }
}
