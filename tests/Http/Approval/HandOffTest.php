<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Approval\Application\HandOffApproved;
use App\Integration\Application\HandoffResult;
use App\Integration\Application\PurchaseHandoff;
use App\Integration\Infrastructure\PendingPaymentHandoff;
use App\Shared\Infrastructure\Mail\InMemoryMailSender;
use App\Shared\Infrastructure\Mail\MailSender;
use App\Shared\Infrastructure\Outbox\OutboxWorker;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Tests\Support\RecordingLogger;

final class HandOffTest extends ApprovalHttpTestCase
{
    private function handOff(string $id): ?\App\Approval\Domain\ApprovalStatus
    {
        $job = $this->container->get(HandOffApproved::class);
        self::assertInstanceOf(HandOffApproved::class, $job);

        return $job->execute($id);
    }

    private function statusOf(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $id]);
    }

    private function notices(string $kind): int
    {
        return (int) $this->scalar('SELECT count(*) FROM notifications WHERE kind = :k', ['k' => $kind]);
    }

    #[Group('AC-41')]
    public function testOnSuccessTheRequestIsCompletedAndTheChildIsRegistered(): void
    {
        $id = $this->openRequest('purchase', 'approved');
        $this->ports->handoffResult = HandoffResult::Succeeded;

        $status = $this->handOff($id);

        self::assertSame('completed', $status?->value);
        self::assertSame('completed', $this->statusOf($id));
        self::assertCount(1, $this->ports->handoffs);
        self::assertSame(
            ['approvalRequestId' => $id, 'parentUserId' => $this->parent, 'playerProfileId' => $this->mia, 'eventRef' => self::EVENT, 'tokens' => 0, 'amountMinor' => 2500, 'currency' => 'USD'],
            $this->ports->handoffs[0],
        );
        self::assertSame([[self::EVENT, $this->mia]], $this->ports->registered);
        self::assertNotNull($this->scalar('SELECT handoff_at FROM approval_requests WHERE id = :i', ['i' => $id]));
        self::assertSame(2, $this->notices('approval_completed'), 'parent and child are told');
    }

    #[Group('AC-41')]
    public function testOnFailureTheRequestRecordsPaymentFailedAndTheChildIsNotRegistered(): void
    {
        $id = $this->openRequest('purchase', 'approved');
        $this->ports->handoffResult = HandoffResult::Failed;

        $status = $this->handOff($id);

        self::assertSame('payment_failed', $status?->value);
        self::assertSame('payment_failed', $this->statusOf($id));
        self::assertSame([], $this->ports->registered);
        self::assertSame(2, $this->notices('approval_payment_failed'), 'parent and child are told');
        $this->signInAsKid();
        self::assertStringContainsString('Payment failed', $this->body($this->get('/family/requests')));
        $this->signInAsParent();
        self::assertStringContainsString('payment failed', $this->body($this->get('/family/approvals')));
    }

    #[Group('AC-41'), Group('AC-86')]
    public function testTheFailureEmailsSayNothingWasBooked(): void
    {
        $id = $this->openRequest('purchase', 'approved');
        $this->ports->handoffResult = HandoffResult::Failed;

        $this->handOff($id);

        $mail = $this->row("SELECT payload FROM outbox WHERE topic = 'approval-outcome' AND payload->>'to' = :e", ['e' => self::KID_EMAIL]);
        self::assertIsArray($mail);
        self::assertStringContainsString('payment did not go through', (string) $mail['payload']);
    }

    #[Group('AC-41')]
    public function testWithTheStandInTheRequestStaysApprovedAndTheLogSaysPaymentsAreNotAvailable(): void
    {
        $logger = new RecordingLogger();
        assert($this->container instanceof \DI\Container);
        $this->container->set(LoggerInterface::class, $logger);
        $this->container->set(PurchaseHandoff::class, new PendingPaymentHandoff($logger));
        $id = $this->openRequest('purchase', 'approved');

        self::assertNull($this->handOff($id));

        self::assertSame('approved', $this->statusOf($id));
        self::assertSame([], $this->ports->registered);
        self::assertSame(0, $this->notices('approval_completed') + $this->notices('approval_payment_failed'));
        self::assertNotEmpty(array_filter($logger->messages, static fn(string $m): bool => str_contains($m, 'payments not available')));
    }

    #[Group('AC-41')]
    public function testTheJobRunsOnceOnlyPerApprovedRequest(): void
    {
        $id = $this->openRequest('purchase', 'approved');
        $this->ports->handoffResult = HandoffResult::Succeeded;

        $this->handOff($id);
        $again = $this->handOff($id);

        self::assertNull($again);
        self::assertCount(1, $this->ports->handoffs);
        self::assertCount(1, $this->ports->registered);
    }

    #[Group('AC-41')]
    public function testOnlyApprovedRequestsAreHandedOver(): void
    {
        foreach (['pending', 'info_requested', 'denied', 'expired', 'auto_denied', 'completed', 'payment_failed'] as $i => $status) {
            $id = $this->openRequest('purchase', $status, '0190aaaa-0000-7000-8000-0000000001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            self::assertNull($this->handOff($id), $status);
        }
        self::assertNull($this->handOff('00000000-0000-7000-8000-000000000000'));

        self::assertSame([], $this->ports->handoffs);
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-41')]
    public function testATokenRequestIsHandedOverWithItsTokens(): void
    {
        $id = $this->openRequest('token_spend', 'approved', amount: 7, payment: 'tokens');
        $this->ports->handoffResult = HandoffResult::Succeeded;

        $this->handOff($id);

        self::assertSame(7, $this->ports->handoffs[0]['tokens']);
        self::assertNull($this->ports->handoffs[0]['amountMinor']);
        self::assertSame('completed', $this->statusOf($id));
    }

    #[Group('AC-41'), Group('AC-48')]
    public function testAnApprovedRsvpRegistersTheChildAndAnApprovedCancellationCancelsThatRsvp(): void
    {
        $rsvp = $this->openRequest('rsvp', 'approved', payment: 'none', amount: 0);
        $cancel = $this->openRequest('rsvp_cancel', 'approved', self::EVENT_2, payment: 'none', amount: 0);

        self::assertSame('completed', $this->handOff($rsvp)?->value);
        self::assertSame('completed', $this->handOff($cancel)?->value);

        self::assertSame([[self::EVENT, $this->mia]], $this->ports->registered);
        self::assertSame([[self::EVENT_2, $this->mia]], $this->ports->rsvpCancelled);
        self::assertSame([], $this->ports->handoffs, 'nothing is paid for an RSVP');
    }

    #[Group('AC-48')]
    public function testAnRsvpTheCapabilityCannotRegisterStaysApproved(): void
    {
        $id = $this->openRequest('rsvp', 'approved', payment: 'none', amount: 0);
        $this->ports->registerSucceeds = false;

        self::assertNull($this->handOff($id));
        self::assertSame('approved', $this->statusOf($id));
    }

    #[Group('AC-41')]
    public function testAnErrorFromThePaymentCapabilityFailsTheJobSoItIsRetriedAndLeavesTheRequestApproved(): void
    {
        $id = $this->openRequest('purchase', 'approved');
        $this->ports->handoffThrows = new \RuntimeException('payment service down');

        try {
            $this->handOff($id);
            self::fail('the job must fail so the outbox retries it');
        } catch (\RuntimeException $e) {
            self::assertSame('payment service down', $e->getMessage());
        }

        self::assertSame('approved', $this->statusOf($id));
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-41')]
    public function testApprovingThroughTheParentsScreenAndRunningTheWorkerCompletesTheRequest(): void
    {
        $mail = new InMemoryMailSender();
        assert($this->container instanceof \DI\Container);
        $this->container->set(MailSender::class, $mail);
        $id = $this->openRequest();
        $this->ports->handoffResult = HandoffResult::Succeeded;
        $this->signInAsParent();
        $this->post('/family/approvals/' . $id . '/decision', ['decision' => 'approve']);
        self::assertSame('approved', $this->statusOf($id));
        self::assertSame([], $this->ports->handoffs, 'the hand-off waits for the worker');

        $worker = $this->container->get(OutboxWorker::class);
        self::assertInstanceOf(OutboxWorker::class, $worker);
        $worker->runOnce(); // runs the hand-off job (and the approval email)
        $worker->runOnce(); // sends the emails the job queued

        self::assertSame('completed', $this->statusOf($id));
        self::assertSame([[self::EVENT, $this->mia]], $this->ports->registered);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE status = 'pending'"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE status = 'failed'"));
        self::assertGreaterThanOrEqual(3, count($mail->sent), 'approval outcome, hand-off outcome to child and parent');
    }
}
