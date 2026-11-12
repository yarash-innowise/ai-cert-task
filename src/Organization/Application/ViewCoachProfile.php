<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CoachProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserProfileRepository;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\CoachMemberships;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/**
 * Who may open a Coach's profile page: the Coach themself, a Super Admin, and people acting under the
 * Coach's organization, the Trainer always and players, parents and fellow Coaches only when the Coach ticked
 * "public". Everything else, including a Coach of another organization, is "not found" (AC-10).
 */
final class ViewCoachProfile
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CoachProfileRepository $profiles,
        private readonly UserProfileRepository $extras,
        private readonly CoachMemberships $memberships,
        private readonly OrganizationRepository $organizations,
    ) {}

    /** @throws NotFound */
    public function execute(CurrentUser $viewer, RequestContext $context, string $coachId): CoachPublicView
    {
        $coach = $this->users->findById($coachId);
        if ($coach === null || $coach->role !== Role::Coach || !$coach->isActive()) {
            throw new NotFound();
        }
        $profile = $this->profiles->find($coach->id);
        $isPublic = $profile->isPublic ?? false;

        $membership = $this->memberships->activeOf($coach->id);
        $underSameOrganization = $membership !== null && $context->organizationId !== null && $context->organizationId->value === $membership->organizationId;
        $self = $viewer->id === $coach->id;
        $staff = $viewer->role === Role::SuperAdmin || ($viewer->role === Role::Trainer && $underSameOrganization);

        if (!$self && !$staff && !($underSameOrganization && $isPublic)) {
            throw new NotFound();
        }

        $organization = $membership === null ? null : $this->organizations->findById($membership->organizationId);

        return new CoachPublicView(
            $coach->id,
            $coach->name,
            $profile->bio ?? null,
            $profile->credentials ?? null,
            $profile->certifications ?? [],
            $isPublic,
            $this->extras->find($coach->id)->photoPath !== null,
            $organization?->businessName,
            $self || $staff,
        );
    }
}
