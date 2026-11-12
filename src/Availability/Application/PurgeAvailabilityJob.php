<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\WeeklyAvailability;
use App\Shared\Infrastructure\Outbox\OutboxMessage;

/**
 * Outbox job `availability.purge`: erases the weekly availability of subjects whose owner was deleted (anonymized).
 * Erasing is idempotent: a subject without a week stays without one.
 */
final class PurgeAvailabilityJob
{
    public const TOPIC = 'availability.purge';

    public function __construct(private readonly AvailabilityRepository $availability) {}

    public function __invoke(OutboxMessage $message): void
    {
        $type = AvailabilitySubject::tryFrom((string) ($message->payload['subject_type'] ?? ''));
        $ids = $message->payload['subject_ids'] ?? null;
        if ($type === null || !is_array($ids)) {
            throw new \InvalidArgumentException('Job payload needs subject_type and subject_ids.');
        }
        foreach ($ids as $id) {
            if (is_string($id)) {
                $this->availability->replace($type, $id, WeeklyAvailability::empty());
            }
        }
    }
}
