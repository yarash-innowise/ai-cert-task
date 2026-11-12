<?php

declare(strict_types=1);

namespace App\Availability\Domain;

/** The Coach is already assigned to this event; the caller's transaction is aborted and must be rolled back. */
final class AssignmentAlreadyExists extends \DomainException {}
