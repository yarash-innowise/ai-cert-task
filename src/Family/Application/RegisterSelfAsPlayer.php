<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Age;
use App\Family\Domain\Gender;
use App\Family\Domain\Policy\PlayerProfilePolicy;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\SelfProfileExists;
use App\Identity\Domain\CurrentUser;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use DateTimeImmutable;

/** A parent account also acts as a player ("Me") and can then train with Trainers itself (AC-31). */
final class RegisterSelfAsPlayer
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly PlayerProfilePolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws Forbidden the actor may not manage a family
     * @throws ValidationFailed the account holder is under 18, or already has a "Me" profile
     */
    public function execute(CurrentUser $actor, DateTimeImmutable $birthDate, ?Gender $gender, ?string $ip = null): PlayerProfile
    {
        if (!$this->policy->canManageFamily($actor)) {
            throw new Forbidden();
        }
        if (!Age::ofBirthDate($birthDate, $this->clock->now())->isAdult()) {
            throw ValidationFailed::forField('birth_date', sprintf('The account holder must be at least %d years old. Add younger players as children.', Age::ADULT));
        }
        [$first, $last] = array_pad(explode(' ', trim($actor->name), 2), 2, '');

        try {
            return $this->transaction->run(function () use ($actor, $birthDate, $gender, $first, $last, $ip): PlayerProfile {
                $profile = $this->profiles->createSelf($actor->id, $first, trim($last), $birthDate, $gender);
                $this->audit->log('player_profile.self_created', $actor->id, 'player_profile', $profile->id, null, ['profile_id' => $profile->id], null, $ip);

                return $profile;
            });
        } catch (SelfProfileExists) {
            throw ValidationFailed::forField('birth_date', 'You already have a "Me" profile.');
        }
    }
}
