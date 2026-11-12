<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(DateInterval::createFromDateString($interval));
    }
}
