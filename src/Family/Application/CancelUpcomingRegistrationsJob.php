<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Integration\Application\EventRegistrations;
use App\Shared\Infrastructure\Outbox\OutboxMessage;

/** Outbox job: after a removal commits, cancels the child's upcoming RSVPs with that Trainer (Epic-02 port). */
final class CancelUpcomingRegistrationsJob
{
    public function __construct(private readonly EventRegistrations $registrations) {}

    public function __invoke(OutboxMessage $message): void
    {
        $profileId = $message->payload['profile_id'] ?? null;
        $organizationId = $message->payload['organization_id'] ?? null;
        if (!is_string($profileId)) {
            throw new \InvalidArgumentException('Job payload has no profile_id.');
        }
        $this->registrations->cancelUpcomingFor($profileId, is_string($organizationId) ? $organizationId : null);
    }
}
