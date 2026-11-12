<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\ImpersonationEndReason;
use DateTimeImmutable;

/** One impersonation as the history report shows it. The duration is always `ended_at - started_at`. */
final readonly class ImpersonationHistoryRow
{
    public function __construct(
        public string $id,
        public string $adminUserId,
        public string $adminName,
        public string $targetUserId,
        public string $targetName,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public ?int $durationSeconds,
        public ?ImpersonationEndReason $endReason,
        public int $actionCount,
    ) {}

    public function durationLabel(): string
    {
        if ($this->durationSeconds === null) {
            return 'In progress';
        }

        return sprintf('%02d:%02d:%02d', intdiv($this->durationSeconds, 3600), intdiv($this->durationSeconds % 3600, 60), $this->durationSeconds % 60);
    }
}
