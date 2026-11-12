<?php

declare(strict_types=1);

namespace App\Family\Application;

final readonly class ContextGroup
{
    /** @param list<ContextItem> $items */
    public function __construct(public string $title, public array $items) {}
}
