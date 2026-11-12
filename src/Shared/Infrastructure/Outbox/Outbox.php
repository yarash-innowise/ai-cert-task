<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Mail\EmailMessage;
use DateTimeImmutable;

/**
 * Transactional outbox: call inside the business transaction so side effects
 * exist only if the change commits (design D12).
 */
interface Outbox
{
    /** @return string outbox id */
    public function enqueueEmail(EmailMessage $message): string;

    /**
     * @param array<string, mixed> $payload
     * @return string outbox id
     */
    public function enqueueJob(string $topic, array $payload = [], ?DateTimeImmutable $availableAt = null): string;
}
