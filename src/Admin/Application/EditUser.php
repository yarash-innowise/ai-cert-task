<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Identity\Domain\AuthTokenPurpose;
use App\Identity\Domain\AuthTokenRepository;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\Role;
use App\Identity\Domain\SessionRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Domain\Clock;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Super Admin edits any user's account fields (AC-80). Optimistic locking: the save succeeds only
 * when the user still has the version the form was rendered from, so two admins never silently
 * overwrite each other. Changing the email also cuts off everything tied to the old address: live
 * one-time links (reset, verification, Trainer setup) are revoked and the user's sessions end; a
 * Trainer who still has to choose a password gets a fresh setup link at the new address (AC-5,
 * AC-14). The caller has already checked the actor is a Super Admin (route gate).
 */
final class EditUser
{
    public const STALE_MESSAGE = 'This record changed since you opened it. Reload to see the latest values; your changes were not saved.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly AuthTokenRepository $tokens,
        private readonly SessionRepository $sessions,
        private readonly NotificationService $notifications,
        private readonly OrganizationRepository $organizations,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    /** @throws NotFound unknown user */
    public function find(string $userId): User
    {
        return $this->users->findById($userId) ?? throw new NotFound();
    }

    /**
     * @throws NotFound unknown user
     * @throws Conflict the record changed since the form was opened, or the user is deleted
     * @throws ValidationFailed the email belongs to another account
     */
    public function execute(string $userId, EditUserInput $input, string $actorUserId, ?string $ip = null): User
    {
        try {
            return $this->transaction->run(function () use ($userId, $input, $actorUserId, $ip): User {
                $current = $this->find($userId);
                if ($current->status === UserStatus::Deleted) {
                    throw new Conflict('A deleted user cannot be edited.');
                }

                $saved = $this->users->updateAccount($userId, $input->name, $input->phone?->e164, $input->email, $input->version);
                if (!$saved) {
                    throw new Conflict(self::STALE_MESSAGE);
                }

                $changed = array_keys(array_filter([
                    'name' => $current->name !== $input->name,
                    'phone' => $current->phone !== $input->phone?->e164,
                    'email' => !$current->email->equals($input->email),
                ]));
                $this->audit->log('user.updated', $actorUserId, 'user', $userId, null, ['changed_fields' => implode(',', $changed)], null, $ip);

                $updated = $this->find($userId);
                if (in_array('email', $changed, true)) {
                    $this->cutOffOldAddress($updated, $actorUserId, $ip);
                }

                return $updated;
            });
        } catch (EmailAlreadyRegistered) {
            throw ValidationFailed::forField('email', 'This email is already registered.');
        }
    }

    /** Links and sessions issued while the old address was the user's must not outlive the change. */
    private function cutOffOldAddress(User $user, string $actorUserId, ?string $ip): void
    {
        $now = $this->clock->now();
        foreach (AuthTokenPurpose::cases() as $purpose) {
            $this->tokens->revokeLive($user->id, $purpose, $now);
        }
        $this->sessions->deleteAllForUser($user->id);

        $reissued = false;
        if ($user->role === Role::Trainer && $user->mustSetPassword) {
            $organization = $this->organizations->findByOwner($user->id);
            $token = $this->tokens->issue($user->id, AuthTokenPurpose::TrainerSetup, $now);
            $this->notifications->email(new EmailMessage('trainer-setup', $user->email->value, [
                'name' => $user->name,
                'business_name' => $organization->businessName ?? '',
                'link' => $this->urls->absolute('/setup/' . $token),
            ]));
            $reissued = true;
        }

        // IDs and flags only (G5): never either address.
        $this->audit->log('user.email_changed', $actorUserId, 'user', $user->id, null, ['setup_link_reissued' => $reissued], null, $ip);
    }
}
