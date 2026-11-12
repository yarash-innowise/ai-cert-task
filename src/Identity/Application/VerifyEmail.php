<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\InvalidToken;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Pdo\TransactionManager;

final class VerifyEmail
{
    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    public function isLive(string $token): bool
    {
        return $this->tokens->peek($token, AuthTokenPurpose::EmailVerification, $this->clock->now()) !== null;
    }

    /** @throws InvalidToken */
    public function execute(string $token): void
    {
        $this->transaction->run(function () use ($token): void {
            $now = $this->clock->now();
            $userId = $this->tokens->consume($token, AuthTokenPurpose::EmailVerification, $now);
            if ($userId === null) {
                throw new InvalidToken();
            }
            $this->users->markEmailVerified($userId, $now);
        });
    }
}
