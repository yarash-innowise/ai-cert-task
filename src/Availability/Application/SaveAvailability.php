<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\InvalidAvailability;
use App\Availability\Domain\WeeklyAvailability;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Replaces a subject's whole week in one transaction (a player profile or a Coach). The caller has
 * already decided that the actor may edit this subject; this class only stores it.
 */
final class SaveAvailability
{
    public const CONFIRMATION = 'Availability saved. Trainers can see these preferences when planning sessions.';

    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly TransactionManager $transaction,
    ) {}

    /** @throws ValidationFailed the database refused the week (overlapping ranges) */
    public function execute(AvailabilitySubject $subject, string $subjectId, WeeklyAvailability $week): void
    {
        try {
            $this->transaction->run(fn() => $this->availability->replace($subject, $subjectId, $week));
        } catch (InvalidAvailability $e) {
            throw new ValidationFailed($e->errors);
        }
    }
}
