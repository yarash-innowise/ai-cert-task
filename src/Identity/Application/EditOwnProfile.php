<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CoachProfile;
use App\Identity\Domain\CoachProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserProfileRepository;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\PhotoUpdater;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Any signed-in user edits their own profile (AC-68..AC-71). Only the DTOs of the sections that apply to the
 * account are accepted; email, role, status, skill level and the creation date are not part of any DTO, so a
 * tampered form cannot change them (AC-69). The text commits first, in one transaction; the photo is replaced
 * separately, so a refused or failed photo never loses the text and never disturbs the old photo (edge case).
 */
final class EditOwnProfile
{
    public function __construct(
        private readonly LoadOwnProfile $load,
        private readonly UserRepository $users,
        private readonly UserProfileRepository $extras,
        private readonly CoachProfileRepository $coaches,
        private readonly PlayerProfileRepository $players,
        private readonly OrganizationRepository $organizations,
        private readonly PhotoUpdater $photos,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @return string|null the photo's error message when the photo was refused or could not be stored (the text WAS saved)
     * @throws Forbidden a section the account does not have was submitted
     * @throws NotFound the account has no organization (Trainer) to save to
     */
    public function execute(CurrentUser $actor, OwnProfileInput $input, ?UploadedFileInterface $photo, ?string $ip = null): ?string
    {
        $allowed = $this->load->sectionsFor($actor);
        foreach ($input->fields as $fields) {
            if (!in_array($fields->section(), $allowed, true)) {
                throw new Forbidden();
            }
        }

        $this->transaction->run(function () use ($actor, $input, $ip): void {
            $this->users->updateProfile($actor->id, $input->name, $input->phone?->e164);
            $this->saveSections($actor, $input->fields);
            $this->audit->log('profile.updated', $actor->id, 'user', $actor->id, null, [
                'sections' => implode(',', array_map(static fn(RoleProfileFields $f): string => $f->section()->value, $input->fields)),
            ], null, $ip);
        });

        if (!PhotoUpdater::isChosen($photo) || $photo === null) {
            return null;
        }
        try {
            $this->photos->replace($photo, fn(string $name): ?string => $this->swapPhoto($actor, $name));
        } catch (ValidationFailed $e) {
            return $e->errors['photo'] ?? 'The photo could not be saved.';
        }
        $this->audit->log('profile.photo_changed', $actor->id, 'user', $actor->id, null, [], null, $ip);

        return null;
    }

    /** @param list<RoleProfileFields> $sections */
    private function saveSections(CurrentUser $actor, array $sections): void
    {
        $current = $this->extras->find($actor->id);
        $emergency = $current->emergencyContact;
        $admin = $current->adminSettings;
        $extrasChanged = false;
        foreach ($sections as $fields) {
            if ($fields instanceof PlayerFields) {
                $profile = $this->load->playerProfileOf($actor) ?? throw new NotFound();
                $this->players->updateDetails($profile->id, $fields->school, $fields->jerseyNumber);
            } elseif ($fields instanceof ParentFields) {
                $emergency = $fields->emergencyContact;
                $extrasChanged = true;
            } elseif ($fields instanceof AdminFields) {
                $admin = $fields->adminSettings;
                $extrasChanged = true;
            } elseif ($fields instanceof CoachFields) {
                $this->coaches->save(new CoachProfile($actor->id, $fields->bio, $fields->credentials, $fields->certifications, $fields->isPublic));
            } elseif ($fields instanceof TrainerFields) {
                $organization = $this->organizations->findByOwner($actor->id) ?? throw new NotFound();
                $this->organizations->updateDetails($organization->id, $fields->businessName, $fields->details);
            }
        }
        if ($extrasChanged) {
            $this->extras->saveText($actor->id, $emergency, $admin);
        }
    }

    private function swapPhoto(CurrentUser $actor, string $name): ?string
    {
        if ($actor->isChildLogin()) {
            $profile = $this->load->playerProfileOf($actor) ?? throw new NotFound();

            return $this->players->swapPhoto($profile->id, $name);
        }

        return $this->extras->swapPhoto($actor->id, $name);
    }
}
