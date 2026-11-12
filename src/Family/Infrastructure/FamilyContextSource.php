<?php

declare(strict_types=1);

namespace App\Family\Infrastructure;

use App\Family\Domain\TrainerAssociations;
use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextSource;
use App\Shared\Domain\OrganizationId;

/**
 * Players and parents act in every (profile, Trainer) pair of their ACTIVE associations; a child
 * login only in its own. Asked on every request, so a removed association stops being a valid
 * context immediately (AC-24, AC-25).
 */
final class FamilyContextSource implements ContextSource
{
    public function __construct(private readonly TrainerAssociations $associations) {}

    public function optionsFor(string $userId, string $role): array
    {
        if ($role !== 'player') {
            return [];
        }
        $options = [];
        foreach ($this->associations->contextsOf($userId) as $entry) {
            $organization = OrganizationId::tryFromString($entry->organizationId);
            if ($organization !== null) {
                $options[] = new ContextOption($organization, $entry->profileId);
            }
        }

        return $options;
    }
}
