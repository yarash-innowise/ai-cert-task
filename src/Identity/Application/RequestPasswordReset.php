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
 * Sends a reset link when the email belongs to an active account. The caller shows the same
 * neutral message whatever happens here (no account enumeration).
 */
final class RequestPasswordReset
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly NotificationService $notifications,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    public function execute(string $emailInput): void
    {
        try {
            $email = Email::fromString($emailInput);
        } catch (ValidationFailed) {
            return;
        }

        $this->transaction->run(function () use ($email): void {
            $user = $this->users->findByEmail($email);
            if ($user === null || !$user->isActive()) {
                return;
            }

            $token = $this->tokens->issue($user->id, AuthTokenPurpose::PasswordReset, $this->clock->now());
            $this->notifications->email(new EmailMessage('password-reset', $user->email->value, [
                'name' => $user->name,
                'link' => $this->urls->absolute('/reset-password/' . $token),
            ]));
        });
    }
}
