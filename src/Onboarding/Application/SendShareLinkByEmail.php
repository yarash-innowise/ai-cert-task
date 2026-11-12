<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Integration\Application\SendShareLinkByEmail as SendShareLinkByEmailPort;
use App\Onboarding\Domain\ActiveLinkExists;
use App\Onboarding\Domain\ShareLinkCode;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\AppUrl;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * The Onboarding implementation of the {@see SendShareLinkByEmailPort} port (AC-85, AC-86): emails the
 * organization's player ShareLink so the person can register later. When the organization has no
 * active link yet one is created. Nothing is sent for an organization or Trainer that is not active.
 */
final class SendShareLinkByEmail implements SendShareLinkByEmailPort
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
        private readonly Clock $clock,
        private readonly AppUrl $urls,
    ) {}

    /**
     * @throws \App\Shared\Http\Exception\ValidationFailed the recipient address is not valid
     * @throws NotFound the organization has no usable link (inactive organization or Trainer)
     */
    public function send(OrganizationId $organization, string $recipientEmail, string $recipientName = ''): void
    {
        $to = Email::fromString($recipientEmail);

        $this->transaction->run(function () use ($organization, $to, $recipientName): void {
            $link = $this->links->findActivePlayerLink($organization);
            if ($link === null) {
                try {
                    $link = $this->links->createPlayerLink($organization, ShareLinkCode::generate(), $this->clock->now());
                    $this->audit->log('share_link.created', null, 'share_link', $link->id, $organization->value, ['share_link_id' => $link->id]);
                } catch (ActiveLinkExists) {
                    $link = $this->links->findActivePlayerLink($organization) ?? throw new \LogicException('Active link vanished.');
                }
            }
            $resolved = $this->links->findUsableByCode($link->code) ?? throw new NotFound();

            $this->notifications->email(new EmailMessage('share-link', $to->value, [
                'name' => mb_substr(trim($recipientName), 0, 200),
                'business_name' => $resolved->businessName,
                'link' => $this->urls->absolute('/join/' . $link->code),
            ]));
        });
    }
}
