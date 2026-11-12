<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CoachProfileRepository
{
    /** @return CoachProfile|null null until the Coach saved the profile once */
    public function find(string $userId): ?CoachProfile;

    public function save(CoachProfile $profile): void;
}
