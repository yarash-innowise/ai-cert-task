<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Issues (and emails) a 24 hour verification link; a new link revokes the earlier one.
 * Registration calls forUser inside its own transaction; resend is neutral about unknown emails.
 */
final class IssueEmailVerification
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly NotificationService $notifications,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    public function forUser(string $userId): void
    {
        $this->transaction->run(function () use ($userId): void {
            $user = $this->users->findById($userId);
            if ($user === null || !$user->isActive() || $user->emailVerifiedAt !== null) {
                return;
            }

            $token = $this->tokens->issue($user->id, AuthTokenPurpose::EmailVerification, $this->clock->now());
            $this->notifications->email(new EmailMessage('email-verification', $user->email->value, [
                'name' => $user->name,
                'link' => $this->urls->absolute('/verify-email/' . $token),
            ]));
        });
    }

    /** Resend by address: does nothing, silently, unless the account is active and unverified. */
    public function resendFor(string $emailInput): void
    {
        try {
            $user = $this->users->findByEmail(Email::fromString($emailInput));
        } catch (ValidationFailed) {
            return;
        }
        if ($user !== null && $user->needsEmailVerification()) {
            $this->forUser($user->id);
        }
    }
}
