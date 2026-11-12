<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A signed-in user flagged must_set_password chooses a password before anything else (AC-15).
 * The account was created by a Super Admin, so choosing the password also settles the email.
 */
final class SetRequiredPassword
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordPolicy $policy,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** @throws ValidationFailed */
    public function execute(string $userId, string $password, string $confirmation, ?string $ip = null): void
    {
        $this->policy->assertAcceptable($password);
        if (!hash_equals($password, $confirmation)) {
            throw ValidationFailed::forField('password_confirmation', 'The two passwords do not match.');
        }

        $this->transaction->run(function () use ($userId, $password, $ip): void {
            $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
            $this->users->markEmailVerified($userId, $this->clock->now());
            $this->audit->log('user.password_changed', $userId, 'user', $userId, null, ['via' => 'forced_change'], null, $ip);
        });
    }
}
