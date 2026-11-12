<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** All valid contexts of a user: the concatenation of every registered source, without duplicates. */
final class ContextOptions
{
    /** @param iterable<ContextSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    /** @return list<ContextOption> */
    public function for(string $userId, string $role): array
    {
        $all = [];
        foreach ($this->sources as $source) {
            foreach ($source->optionsFor($userId, $role) as $option) {
                foreach ($all as $known) {
                    if ($known->equals($option)) {
                        continue 2;
                    }
                }
                $all[] = $option;
            }
        }

        return $all;
    }
}
