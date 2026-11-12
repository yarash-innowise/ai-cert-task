<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\WeeklyAvailability;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;

/**
 * A player or a parent sets the weekly availability of ONE player profile; a parent does it separately for
 * each child (AC-61). The owner only: a foreign profile is 404, a child login is refused (the week is the
 * parent's data, AC-47).
 */
final class SavePlayerAvailability
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly PlayerProfilePolicy $policy,
        private readonly AvailabilityRepository $availability,
        private readonly SaveAvailability $save,
    ) {}

    /**
     * @throws Forbidden a child login or a non-player account
     * @throws NotFound not the actor's profile
     */
    public function view(CurrentUser $actor, string $profileId): PlayerAvailabilityView
    {
        $profile = $this->ownedProfile($actor, $profileId);

        return new PlayerAvailabilityView($profile, $this->availability->week(AvailabilitySubject::PlayerProfile, $profile->id));
    }

    /**
     * @throws Forbidden a child login or a non-player account
     * @throws NotFound not the actor's profile
     * @throws \App\Shared\Http\Exception\ValidationFailed overlapping ranges
     */
    public function execute(CurrentUser $actor, string $profileId, WeeklyAvailability $week): PlayerProfile
    {
        $profile = $this->ownedProfile($actor, $profileId);
        $this->save->execute(AvailabilitySubject::PlayerProfile, $profile->id, $week);

        return $profile;
    }

    private function ownedProfile(CurrentUser $actor, string $profileId): PlayerProfile
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);
        if ($profile === null || !$this->policy->canManage($actor, $profile)) {
            throw new NotFound();
        }

        return $profile;
    }
}
