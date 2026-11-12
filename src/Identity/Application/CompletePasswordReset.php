<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\InvalidToken;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\SessionRepository;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Uses a reset link once: new password, every session of the user ends, the change is audited.
 */
final class CompletePasswordReset
{
    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly PasswordPolicy $policy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws ValidationFailed the password is not acceptable (the link stays usable)
     * @throws InvalidToken the link is unknown, used, revoked or expired
     */
    public function execute(string $token, string $password, string $confirmation, ?string $ip = null): void
    {
        $this->policy->assertAcceptable($password);
        if (!hash_equals($password, $confirmation)) {
            throw ValidationFailed::forField('password_confirmation', 'The two passwords do not match.');
        }

        $this->transaction->run(function () use ($token, $password, $ip): void {
            $now = $this->clock->now();
            $userId = $this->tokens->consume($token, AuthTokenPurpose::PasswordReset, $now);
            if ($userId === null) {
                throw new InvalidToken();
            }
            $user = $this->users->findById($userId);
            if ($user === null || !$user->isActive()) {
                throw new InvalidToken();
            }

            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
            $this->sessions->deleteAllForUser($userId);
            $this->audit->log('user.password_changed', $userId, 'user', $userId, null, ['via' => 'reset'], null, $ip);
        });
    }
}
