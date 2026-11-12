<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\PaymentType;
use App\Approval\Domain\Policy\ApprovalRequestPolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Integration\Application\EventRegistrations;
use App\Integration\Application\SpendResult;
use App\Integration\Application\TokenSpend;
use App\Organization\Domain\OrganizationRepository;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * A child login spends tokens (AC-43). With "Allow token spending without approval" OFF (the default) the
 * spend follows the same approval workflow as USD: a request for the parent, rules snapshotted. With it ON
 * the spend goes straight to the token capability (Epic-05), the child is registered only when the spend
 * succeeded, and the parent gets an informational notice (email and in-app) that has no approve action.
 * The amount of a token request is a whole number of tokens.
 */
final class RequestTokenSpend
{
    public const MAX_TOKENS = 100000;

    public function __construct(
        private readonly OpenApprovalRequest $open,
        private readonly ApprovalRequestPolicy $policy,
        private readonly PlayerProfileRepository $profiles,
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
        private readonly TokenSpend $tokens,
        private readonly EventRegistrations $registrations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly ResolveRequestedEvent $events,
    ) {}

    /**
     * @param int $tokens how many tokens the child wants to spend (at least 1)
     * @throws Forbidden the actor is not a child login
     * @throws ValidationFailed the number of tokens is not valid
     */
    public function execute(CurrentUser $actor, RequestContext $context, NewRequestInput $input, int $tokens, ?string $ip = null): TokenSpendOutcome
    {
        if (!$this->policy->canRequest($actor)) {
            throw new Forbidden();
        }
        if ($tokens < 1 || $tokens > self::MAX_TOKENS) {
            throw ValidationFailed::forField('tokens', sprintf('Enter a whole number of tokens from 1 to %d.', self::MAX_TOKENS));
        }
        $profile = $this->profiles->findByLogin($actor->id) ?? throw new Forbidden();
        $request = new NewRequestInput($input->eventRef, null, $tokens, 'USD', $input->childNote);

        if (!$profile->tokenSpendWithoutApproval) {
            return TokenSpendOutcome::requested($this->open->execute($actor, $context, ApprovalKind::TokenSpend, PaymentType::Tokens, $request, $ip));
        }

        $parent = $this->users->findById($profile->ownerUserId);
        if ($parent === null || !$parent->isActive()) {
            throw new Conflict('Your parent account is not active, so tokens cannot be spent right now.');
        }
        $organization = $context->organizationId ?? throw new Conflict('Choose a Trainer first.');
        $eventTitle = $this->events->execute($organization, $input->eventRef)->title;

        // The payment capability is called outside any transaction; the notice follows only a real spend.
        $result = $this->tokens->spend($parent->id, $tokens, sprintf('child-%s-event-%s', $profile->id, $input->eventRef));
        if ($result !== SpendResult::Spent) {
            return TokenSpendOutcome::spent($result);
        }
        $this->registrations->register($input->eventRef, $profile->id);

        $businessName = $this->organizations->findById($organization->value)->businessName ?? '';
        $this->transaction->run(function () use ($actor, $profile, $parent, $organization, $input, $tokens, $businessName, $eventTitle, $ip): void {
            $event = $eventTitle;
            $this->notifications->notify(
                $parent->id,
                'token_spend_notice',
                sprintf('%s spent %d tokens', $profile->fullName(), $tokens),
                sprintf('%s spent %d tokens on %s (%s). No approval is needed.', $profile->firstName, $tokens, $event, $businessName),
                ['profile_id' => $profile->id, 'organization_id' => $organization->value, 'tokens' => $tokens, 'informational' => true],
                new EmailMessage('token-spend-notice', $parent->email->value, [
                    'parent_name' => $parent->name,
                    'child_name' => $profile->fullName(),
                    'event_label' => $event,
                    'business_name' => $businessName,
                    'tokens' => $tokens,
                ]),
            );
            $this->audit->log('token_spend.completed', $actor->id, 'player_profile', $profile->id, $organization->value, [
                'profile_id' => $profile->id,
                'event_ref' => $input->eventRef,
                'tokens' => $tokens,
                'approval_required' => false,
            ], null, $ip);
        });

        return TokenSpendOutcome::spent($result);
    }
}
