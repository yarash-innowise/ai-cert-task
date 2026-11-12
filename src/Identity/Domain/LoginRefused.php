<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

/**
 * A sign-in was refused. The message is safe to show: for invalid credentials it never says
 * which of email or password was wrong (AC-2).
 */
final class LoginRefused extends \RuntimeException
{
    public function __construct(
        public readonly LoginFailure $reason,
        string $message,
        public readonly ?DateTimeImmutable $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self(LoginFailure::InvalidCredentials, 'Email or password is incorrect.');
    }

    public static function locked(DateTimeImmutable $until, DateTimeImmutable $now): self
    {
        $minutes = max(1, (int) ceil(($until->getTimestamp() - $now->getTimestamp()) / 60));

        return new self(
            LoginFailure::Locked,
            sprintf('Too many failed sign-in attempts. Try again in %d minute%s.', $minutes, $minutes === 1 ? '' : 's'),
            $until,
        );
    }

    public static function deactivated(): self
    {
        return new self(LoginFailure::Deactivated, 'Account deactivated. Contact support.');
    }

    public static function unverified(): self
    {
        return new self(LoginFailure::Unverified, 'Please verify your email address before signing in.');
    }
}
