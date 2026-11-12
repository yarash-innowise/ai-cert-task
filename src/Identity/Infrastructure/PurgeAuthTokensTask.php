<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\AuthTokenRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;

/** Removes tokens that expired more than a week ago. */
final class PurgeAuthTokensTask implements ScheduledTask
{
    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly Clock $clock,
    ) {}

    public function name(): string
    {
        return 'tokens:purge';
    }

    public function intervalSeconds(): int
    {
        return 3600;
    }

    public function run(): void
    {
        $this->tokens->purgeOlderThan($this->clock->now()->modify('-7 days'));
    }
}
