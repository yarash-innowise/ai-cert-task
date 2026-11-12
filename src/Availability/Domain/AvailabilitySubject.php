<?php

declare(strict_types=1);

namespace App\Availability\Domain;

/** Whose week an availability belongs to: a player profile (a child or "Me") or a Coach (user id). */
enum AvailabilitySubject: string
{
    case PlayerProfile = 'player_profile';
    case Coach = 'coach';
}
