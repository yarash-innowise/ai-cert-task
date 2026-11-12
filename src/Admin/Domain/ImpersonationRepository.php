<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use DateTimeImmutable;

interface ImpersonationRepository
{
    public function start(string $adminUserId, string $targetUserId, DateTimeImmutable $at): Impersonation;

    public function find(string $id): ?Impersonation;

    public function touch(string $id, DateTimeImmutable $at): void;

    /** Closes an open impersonation; false when it was already closed. */
    public function end(string $id, DateTimeImmutable $endedAt, ImpersonationEndReason $reason): bool;

    /**
     * Open impersonations the sweep must look at, with whether a session row still carries them.
     *
     * @return list<array{impersonation: Impersonation, sessionAlive: bool}>
     */
    public function open(): array;
}
