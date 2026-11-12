<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Super Admin creates a Trainer (AC-12, AC-13): user, organization, 7-day setup link, queued
 * email and audit row in one transaction, so a failure leaves nothing behind. The caller must
 * already have checked that the actor is a Super Admin (route gate).
 */
final class CreateTrainer
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
        private readonly AuthTokenRepository $tokens,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    /** @throws ValidationFailed the email is already registered (any letter case) */
    public function execute(CreateTrainerInput $input, string $actorUserId, ?string $ip = null): User
    {
        try {
            return $this->transaction->run(function () use ($input, $actorUserId, $ip): User {
                $now = $this->clock->now();
                $user = $this->users->create(
                    $input->email,
                    $input->trainerName,
                    Role::Trainer,
                    null,
                    null,
                    true,
                    $input->phone->e164,
                );
                $organization = $this->organizations->create($user->id, $input->businessName);
                $token = $this->tokens->issue($user->id, AuthTokenPurpose::TrainerSetup, $now);

                $this->notifications->email(new EmailMessage('trainer-setup', $user->email->value, [
                    'name' => $user->name,
                    'business_name' => $organization->businessName,
                    'link' => $this->urls->absolute('/setup/' . $token),
                ]));
                $this->audit->log(
                    'trainer.created',
                    $actorUserId,
                    'user',
                    $user->id,
                    $organization->id,
                    ['organization_id' => $organization->id, 'business_name' => $organization->businessName, 'role' => Role::Trainer->value],
                    null,
                    $ip,
                );

                return $user;
            });
        } catch (EmailAlreadyRegistered) {
            throw ValidationFailed::forField('email', 'This email is already registered.');
        }
    }
}
