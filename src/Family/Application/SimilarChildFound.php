<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\PlayerProfile;

/** A resembling child of the same parent exists; the parent may confirm and create anyway (AC-30). */
final class SimilarChildFound extends \RuntimeException
{
    public function __construct(public readonly PlayerProfile $existing, public readonly ?int $existingAge)
    {
        parent::__construct('A child with a similar name and age already exists.');
    }
}
