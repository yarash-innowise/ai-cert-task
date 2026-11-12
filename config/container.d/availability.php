<?php

declare(strict_types=1);

use App\Availability\Application\PurgeAvailabilityJob;
use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\CoachAssignments;
use App\Availability\Infrastructure\PdoAvailabilityRepository;
use App\Availability\Infrastructure\PdoCoachAssignments;

use function DI\add;
use function DI\autowire;

/** Availability module bindings. */
return [
    AvailabilityRepository::class => autowire(PdoAvailabilityRepository::class),
    CoachAssignments::class => autowire(PdoCoachAssignments::class),
    // The erasure of a deleted user's weekly availability (queued by the Admin lifecycle) runs in this module.
    'job.handlers' => add([[PurgeAvailabilityJob::TOPIC, PurgeAvailabilityJob::class]]),
];
