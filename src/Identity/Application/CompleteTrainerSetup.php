<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\InvalidToken;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Uses a Trainer setup link once: the Trainer chooses a password, the email counts as verified
 * (the link proves control of the mailbox) and must_set_password is cleared (AC-14, AC-15).
 */
final class CompleteTrainerSetup
{
    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly PasswordPolicy $policy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    public function isLive(string $token): bool
    {
        return $this->tokens->peek($token, AuthTokenPurpose::TrainerSetup, $this->clock->now()) !== null;
    }

    /**
     * @return string the Trainer's user id
     * @throws ValidationFailed the password is not acceptable (the link stays usable)
     * @throws InvalidToken the link is unknown, used, revoked or expired
     */
    public function execute(string $token, string $password, string $confirmation, ?string $ip = null): string
    {
        $this->policy->assertAcceptable($password);
        if (!hash_equals($password, $confirmation)) {
            throw ValidationFailed::forField('password_confirmation', 'The two passwords do not match.');
        }

        return $this->transaction->run(function () use ($token, $password, $ip): string {
            $now = $this->clock->now();
            $userId = $this->tokens->consume($token, AuthTokenPurpose::TrainerSetup, $now);
            if ($userId === null) {
                throw new InvalidToken();
            }
            $user = $this->users->findById($userId);
            if ($user === null || !$user->isActive() || $user->role !== Role::Trainer) {
                throw new InvalidToken();
            }

            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
            $this->users->markEmailVerified($userId, $now);
            $this->audit->log('user.password_changed', $userId, 'user', $userId, null, ['via' => 'trainer_setup'], null, $ip);

            return $userId;
        });
    }
}
