<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Admin\Domain\Impersonation;
use App\Admin\Domain\ImpersonationRepository;
use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A Super Admin starts viewing the application as another user (AC-50, AC-51). Only a Super Admin who is not
 * already impersonating may start; a Super Admin and a deleted user are refused with a message; an inactive
 * user may be viewed read-only for support (Q-01.16). The session keeps its row but gets a new identifier.
 */
final class StartImpersonation
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ImpersonationRepository $impersonations,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /** The user a confirmation page names; the same refusals as the start itself, without changing anything. */
    public function preview(CurrentUser $actor, SessionState $session, string $targetUserId): User
    {
        $this->assertMayStart($actor, $session);

        return $this->assertTarget($targetUserId);
    }

    /**
     * @throws Forbidden not a Super Admin, or already impersonating (no nesting)
     * @throws NotFound unknown user
     * @throws ValidationFailed the target is a Super Admin or deleted
     */
    public function execute(CurrentUser $actor, SessionState $session, string $targetUserId, ?string $ip = null): Impersonation
    {
        $this->assertMayStart($actor, $session);

        return $this->transaction->run(function () use ($actor, $session, $targetUserId, $ip): Impersonation {
            $target = $this->assertTarget($targetUserId);
            $impersonation = $this->impersonations->start($actor->id, $target->id, $this->clock->now());
            $session->beginImpersonation($target->id, $impersonation->id);
            $this->audit->log('impersonation.started', $actor->id, 'user', $target->id, null, [
                'impersonation_id' => $impersonation->id,
                'target_user_id' => $target->id,
                'target_role' => $target->role->value,
                'target_status' => $target->status->value,
            ], null, $ip);

            return $impersonation;
        });
    }

    private function assertMayStart(CurrentUser $actor, SessionState $session): void
    {
        if ($actor->role !== Role::SuperAdmin || $session->session() === null || $session->session()->isImpersonating()) {
            throw new Forbidden();
        }
    }

    private function assertTarget(string $targetUserId): User
    {
        $target = $this->users->findById($targetUserId) ?? throw new NotFound();
        if ($target->role === Role::SuperAdmin) {
            throw ValidationFailed::forField('user', 'A Super Admin cannot be impersonated.');
        }
        if ($target->status === UserStatus::Deleted) {
            throw ValidationFailed::forField('user', 'A deleted user cannot be impersonated.');
        }

        return $target;
    }
}
