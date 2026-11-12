<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Age;
use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Family\Domain\TrainerOption;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\ImageStore;
use App\Shared\Infrastructure\Storage\PhotoUpdater;
use App\Shared\Infrastructure\Storage\StorageFailed;

/**
 * A parent creates a child profile (AC-27..AC-30): age 0-17 from the birth date, a warning for a
 * resembling child until the parent confirms. Associating with Trainers is the next, separate
 * step (see {@see self::trainerChoicesFor()} and {@see AddChildToTrainer}).
 */
final class AddChild
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly SimilarChildDetector $similar,
        private readonly PlayerProfilePolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly ImageStore $images,
    ) {}

    /**
     * @throws Forbidden the actor may not manage a family
     * @throws ValidationFailed the age is outside 0-17 (message names the range)
     * @throws SimilarChildFound a resembling child exists and the input did not confirm
     */
    public function execute(CurrentUser $actor, AddChildInput $input, ?string $ip = null): PlayerProfile
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $age = Age::ofBirthDate($input->birthDate, $this->clock->now());
        if (!$age->isChildAge()) {
            throw ValidationFailed::forField('birth_date', Age::childRangeMessage());
        }
        if (!$input->confirmSimilar) {
            $existing = $this->similar->find($actor->id, $input->firstName, $input->lastName, $input->birthDate);
            if ($existing !== null) {
                throw new SimilarChildFound($existing, $existing->age($this->clock->now()));
            }
        }

        // The optional photo is validated and stored first: a refused file means no child is created and the
        // form is shown again (AC-27, AC-71). A file stored by an attempt that then fails is removed again.
        $photo = PhotoUpdater::isChosen($input->photo) && $input->photo !== null ? $this->images->preparePhoto($input->photo) : null;
        $photoName = null;
        if ($photo !== null) {
            try {
                $photoName = $this->images->storePhoto($photo);
            } catch (StorageFailed) {
                throw ValidationFailed::forField('photo', 'The photo could not be saved. Please try again.');
            }
        }

        try {
            return $this->transaction->run(function () use ($actor, $input, $ip, $photoName): PlayerProfile {
                $profile = $this->profiles->createChild($actor->id, $input->firstName, $input->lastName, $input->birthDate, $input->gender, $input->school, $photoName);
                $this->audit->log('child.created', $actor->id, 'player_profile', $profile->id, null, ['profile_id' => $profile->id], null, $ip);

                return $profile;
            });
        } catch (\Throwable $e) {
            if ($photoName !== null) {
                $this->images->discardPhoto($photoName);
            }
            throw $e;
        }
    }

    /**
     * The Trainers the parent can offer for this profile: those the family already trains with,
     * minus those the profile is already associated with. Zero: no prompt; one: yes/no; several: checklist.
     *
     * @return list<TrainerOption>
     */
    public function trainerChoicesFor(CurrentUser $actor, PlayerProfile $profile): array
    {
        $current = $this->associations->activeForProfiles([$profile->id])[$profile->id] ?? [];
        $taken = array_map(static fn($a): string => $a->organizationId, $current);

        return array_values(array_filter(
            $this->associations->trainersOfOwner($actor->id),
            static fn(TrainerOption $o): bool => !in_array($o->organizationId, $taken, true),
        ));
    }
}
