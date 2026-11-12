<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\UserRepository;
use App\Onboarding\Domain\PrefillToken;
use App\Onboarding\Domain\RegistrationPrefill;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\ValidationFailed;

/**
 * Turns the token of a pre-fill link into a usable pre-fill. Tampered, unknown, expired and consumed
 * tokens (and those of an inactive organization) all give null: the caller shows a blank, ordinary
 * registration and writes nothing.
 */
final class ResolvePrefill
{
    public function __construct(
        private readonly RegistrationPrefillRepository $prefills,
        private readonly UserRepository $users,
        private readonly Clock $clock,
    ) {}

    public function execute(string $token): ?RegistrationPrefill
    {
        $token = trim($token);
        if (!PrefillToken::isWellFormed($token)) {
            return null;
        }

        return $this->prefills->findUsableByHash(PrefillToken::hash($token), $this->clock->now());
    }

    /** True when the pre-fill's email already has an account: the person is asked to sign in instead of registering. */
    public function accountExists(RegistrationPrefill $prefill): bool
    {
        try {
            return $this->users->findByEmail(Email::fromString($prefill->email())) !== null;
        } catch (ValidationFailed) {
            return false;
        }
    }
}
