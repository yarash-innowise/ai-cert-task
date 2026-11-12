<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\LoginRefused;
use App\Identity\Domain\LoginThrottle;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\ValidationFailed;
use Psr\Log\LoggerInterface;

/**
 * Checks credentials. Order matters: the throttle first (a correct password is refused while
 * locked), then a constant-work password check (also for unknown emails), then account status,
 * which is only revealed to someone who proved the password.
 */
final class LoginUser
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws LoginRefused */
    public function execute(string $emailInput, string $password, string $ip): User
    {
        $now = $this->clock->now();
        $key = mb_strtolower(trim($emailInput));

        // Atomic per email and per IP: refuses when locked, otherwise holds one attempt slot (counted as a
        // failure) so parallel requests cannot all pass the check before any of them is recorded.
        $reservation = $this->throttle->reserveAttempt($key, $ip, $now);
        if ($reservation->lockedUntil !== null) {
            $this->logger->warning('Sign-in refused: throttled', ['ip' => $ip]);

            throw LoginRefused::locked($reservation->lockedUntil, $now);
        }

        $user = null;
        try {
            $user = $this->users->findByEmail(Email::fromString($emailInput));
        } catch (ValidationFailed) {
            // A malformed address is treated exactly like an unknown one.
        }

        $hash = $user?->passwordHash;
        if ($user === null || $hash === null || $user->status === UserStatus::Deleted) {
            $this->hasher->dummyVerify($password);

            $this->fail($ip);
        }

        if (!$this->hasher->verify($password, $hash)) {
            $this->fail($ip);
        }

        if ($user->status === UserStatus::Inactive) {
            $this->throttle->release($reservation);

            throw LoginRefused::deactivated();
        }
        if ($user->needsEmailVerification()) {
            $this->throttle->release($reservation);

            throw LoginRefused::unverified();
        }

        $this->throttle->recordSuccess($reservation);
        if ($this->hasher->needsRehash($hash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        return $user;
    }

    /** The reserved attempt already counts as a failure; nothing more to record. */
    private function fail(string $ip): never
    {
        $this->logger->info('Sign-in failed', ['ip' => $ip]);

        throw LoginRefused::invalidCredentials();
    }
}
