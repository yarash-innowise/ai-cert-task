<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextOptions;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\NotFound;

/**
 * Chooses the Trainer context for the rest of the session (AC-24, AC-25). A context the user is
 * not associated with answers 404, the same as any foreign resource.
 */
final class SwitchContext
{
    public function __construct(private readonly ContextOptions $options) {}

    /** @throws NotFound the user may not act in this organization with this profile */
    public function execute(SessionState $session, CurrentUser $user, string $organizationId, string $profileId): void
    {
        $organization = OrganizationId::tryFromString($organizationId);
        if ($organization === null) {
            throw new NotFound();
        }
        $wanted = new ContextOption($organization, $profileId);
        foreach ($this->options->for($user->id, $user->role->value) as $option) {
            if ($option->equals($wanted)) {
                $session->setActiveContext($organization->value, $profileId);

                return;
            }
        }

        throw new NotFound();
    }
}
