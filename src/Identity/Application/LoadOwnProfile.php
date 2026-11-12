<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CoachProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserProfileRepository;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Http\Exception\NotFound;

/**
 * Reads what the signed-in user's own profile page needs (AC-68, AC-69). Which sections apply depends on the
 * account: Player (a child login, or a parent who also trains), Parent, Coach, Trainer, Super Admin.
 */
final class LoadOwnProfile
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserProfileRepository $extras,
        private readonly CoachProfileRepository $coaches,
        private readonly PlayerProfileRepository $players,
        private readonly OrganizationRepository $organizations,
    ) {}

    /** @return list<ProfileSection> */
    public function sectionsFor(CurrentUser $actor): array
    {
        return match ($actor->role) {
            Role::SuperAdmin => [ProfileSection::Admin],
            Role::Trainer => [ProfileSection::Trainer],
            Role::Coach => [ProfileSection::Coach],
            Role::Player => $actor->isChildLogin()
                ? [ProfileSection::Player]
                : ($this->players->findSelf($actor->id) !== null ? [ProfileSection::Parent, ProfileSection::Player] : [ProfileSection::Parent]),
        };
    }

    /** The player profile whose school, jersey number and (for child logins) photo the account edits, if any. */
    public function playerProfileOf(CurrentUser $actor): ?PlayerProfile
    {
        if ($actor->role !== Role::Player) {
            return null;
        }

        return $actor->isChildLogin() ? $this->players->findByLogin($actor->id) : $this->players->findSelf($actor->id);
    }

    public function execute(CurrentUser $actor): OwnProfile
    {
        $user = $this->users->findById($actor->id) ?? throw new NotFound();
        $sections = $this->sectionsFor($actor);
        $extras = $this->extras->find($user->id);
        $player = $this->playerProfileOf($actor);

        $values = [];
        if (in_array(ProfileSection::Player, $sections, true)) {
            $values['school'] = $player->school ?? '';
            $values['jersey_number'] = $player === null || $player->jerseyNumber === null ? '' : (string) $player->jerseyNumber;
        }
        if (in_array(ProfileSection::Parent, $sections, true)) {
            $values['emergency_contact'] = $extras->emergencyContact ?? '';
        }
        if (in_array(ProfileSection::Coach, $sections, true)) {
            $coach = $this->coaches->find($user->id);
            $values['bio'] = $coach->bio ?? '';
            $values['credentials'] = $coach->credentials ?? '';
            $values['certifications'] = implode("\n", $coach->certifications ?? []);
            $values['is_public'] = $coach->isPublic ?? false;
        }
        if (in_array(ProfileSection::Trainer, $sections, true)) {
            $organization = $this->organizations->findByOwner($user->id);
            $values['business_name'] = $organization->businessName ?? '';
            $values['details'] = $organization === null ? '' : ($this->organizations->detailsOf($organization->id) ?? '');
        }
        if (in_array(ProfileSection::Admin, $sections, true)) {
            $values['admin_settings'] = $extras->adminSettings ?? '';
        }

        $thumbnail = null;
        if ($actor->isChildLogin()) {
            $thumbnail = $player !== null && $player->photoPath !== null ? '/media/profile-thumb/' . $player->id : null;
        } elseif ($extras->photoPath !== null) {
            $thumbnail = '/media/user-thumb/' . $user->id;
        }

        return new OwnProfile(
            $user->id,
            $user->name,
            $user->email->value,
            $user->role,
            $user->phone,
            $user->createdAt,
            $player?->skillLevel,
            $player !== null,
            $sections,
            $values,
            $thumbnail,
        );
    }
}
