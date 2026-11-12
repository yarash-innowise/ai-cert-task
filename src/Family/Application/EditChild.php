<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\PhotoUpdater;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The parent edits a child profile (AC-27, AC-71): the text changes commit first, the photo is replaced
 * separately, so a refused or failed photo never loses the text and never disturbs the old photo.
 */
final class EditChild
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly PlayerProfilePolicy $policy,
        private readonly PhotoUpdater $photos,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @return string|null the photo's error message when the photo was refused or could not be stored (the text WAS saved)
     * @throws Forbidden the actor is a child login or not a parent account
     * @throws NotFound not the actor's child
     */
    public function execute(CurrentUser $actor, string $profileId, EditChildInput $input, ?UploadedFileInterface $photo, ?string $ip = null): ?string
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        $profile = $this->profiles->findOwned($profileId, $actor->id);
        if ($profile === null || !$profile->isChild() || !$this->policy->canManage($actor, $profile)) {
            throw new NotFound();
        }

        $this->transaction->run(function () use ($actor, $profile, $input, $ip): void {
            $this->profiles->updateChild($profile->id, $input->firstName, $input->lastName, $input->school, $input->jerseyNumber);
            $this->audit->log('child.updated', $actor->id, 'player_profile', $profile->id, null, ['profile_id' => $profile->id], null, $ip);
        });

        if (!PhotoUpdater::isChosen($photo) || $photo === null) {
            return null;
        }
        try {
            $this->photos->replace($photo, fn(string $name): ?string => $this->profiles->swapPhoto($profile->id, $name));
        } catch (ValidationFailed $e) {
            return $e->errors['photo'] ?? 'The photo could not be saved.';
        }
        $this->audit->log('child.photo_changed', $actor->id, 'player_profile', $profile->id, null, ['profile_id' => $profile->id], null, $ip);

        return null;
    }
}
