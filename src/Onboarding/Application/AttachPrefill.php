<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Onboarding\Domain\RegistrationPrefill;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A camp-form email that already had an account: after the person signs in, the pre-fill is attached
 * to THAT account (AC-85 edge). Only the account whose email the pre-fill carries may use it, so
 * a forwarded link never associates someone else; no second account is ever created.
 */
final class AttachPrefill
{
    public function __construct(
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly RegistrationPrefillRepository $prefills,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
    ) {}

    /**
     * Read-only look for the GET page: the outcome that needs no write (wrong account, no profile,
     * already associated), or null when a confirmation is due (F-04).
     */
    public function preview(CurrentUser $actor, RegistrationPrefill $prefill): ?PrefillAttachment
    {
        if ($prefill->email() === '' || $prefill->email() !== $actor->email) {
            return PrefillAttachment::WrongAccount;
        }
        $self = $this->profiles->findSelf($actor->id);
        if ($self === null) {
            return PrefillAttachment::NeedsProfile;
        }

        return $this->associations->activeAssociation($self->id, $prefill->organizationId) !== null ? PrefillAttachment::AlreadyAssociated : null;
    }

    public function execute(CurrentUser $actor, RegistrationPrefill $prefill, ?string $ip = null): PrefillAttachment
    {
        if ($prefill->email() === '' || $prefill->email() !== $actor->email) {
            return PrefillAttachment::WrongAccount;
        }
        $self = $this->profiles->findSelf($actor->id);
        if ($self === null) {
            return PrefillAttachment::NeedsProfile;
        }

        return $this->transaction->run(function () use ($actor, $prefill, $self, $ip): PrefillAttachment {
            $organization = OrganizationId::fromString($prefill->organizationId);
            if (!$this->prefills->consume($organization, $prefill->id, $this->clock->now())) {
                return PrefillAttachment::WrongAccount;
            }
            $added = $this->associations->associate($self->id, $prefill->organizationId, null, $this->clock->now());
            if ($added) {
                $this->audit->log('player.joined_via_prefill', $actor->id, 'player_profile', $self->id, $prefill->organizationId, ['profile_id' => $self->id, 'trainer' => $prefill->businessName], null, $ip);
            }

            return $added ? PrefillAttachment::Attached : PrefillAttachment::AlreadyAssociated;
        });
    }
}
