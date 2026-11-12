<?php

declare(strict_types=1);

namespace App\Family\Application;

final readonly class FamilyOverview
{
    /**
     * @param list<ChildListItem> $children
     * @param list<\App\Family\Domain\TrainerOption> $trainers every Trainer the family trains with
     */
    public function __construct(public ?ChildListItem $me, public array $children, public array $trainers = []) {}
}
