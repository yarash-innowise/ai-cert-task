<?php

declare(strict_types=1);

namespace App\Family\Application;

/** What the context selector shows: titled groups of contexts, and the one in force right now. */
final readonly class ContextMenu
{
    /** @param list<ContextGroup> $groups */
    public function __construct(public array $groups, public ?ContextItem $current) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
