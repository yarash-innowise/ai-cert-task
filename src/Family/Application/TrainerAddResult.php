<?php

declare(strict_types=1);

namespace App\Family\Application;

final readonly class TrainerAddResult
{
    public function __construct(public string $businessName, public bool $added) {}
}
