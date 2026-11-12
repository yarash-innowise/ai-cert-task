<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\Application\ApprovedPurchaseHandoff;
use App\Integration\Application\EventCatalog;
use App\Integration\Application\EventRegistrations;
use App\Integration\Application\HandoffResult;
use App\Integration\Application\PurchaseHandoff;
use App\Integration\Application\SpendResult;
use App\Integration\Application\TokenBalance;
use App\Integration\Application\TokenSpend;
use App\Integration\Infrastructure\EmptyEventCatalog;
use App\Integration\Infrastructure\NoopEventRegistrations;
use App\Integration\Infrastructure\PendingPaymentHandoff;
use App\Integration\Infrastructure\UnavailableTokenSpend;
use App\Integration\Infrastructure\ZeroTokenBalance;
use App\Shared\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingLogger;

/** The Epic-02 / Epic-05 stand-ins: each logs its call and returns its documented neutral result. */
final class IntegrationStubsTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    #[Group('AC-46')]
    public function testTheEventCatalogHasNoEventsAndSaysSo(): void
    {
        $catalog = new EmptyEventCatalog($this->logger);

        self::assertSame([], $catalog->eligibleEvents(OrganizationId::fromString('0190aaaa-0000-7000-8000-00000000000a'), 'profile'));
        self::assertSame([], $catalog->eligibleEvents(null));
        self::assertFalse($catalog->exists('event-1'));
        self::assertNull($catalog->find(OrganizationId::fromString('0190aaaa-0000-7000-8000-00000000000a'), 'event-1'), 'unknown event: requests are refused');
        self::assertCount(4, $this->logger->messages);
    }

    #[Group('AC-46')]
    public function testEventRegistrationsRegisterAndCancelNothingAndSaySo(): void
    {
        $registrations = new NoopEventRegistrations($this->logger);

        self::assertFalse($registrations->register('event-1', 'profile'));
        self::assertSame(0, $registrations->cancelUpcomingFor('profile'));
        self::assertCount(2, $this->logger->messages);
    }

    #[Group('AC-41')]
    public function testThePaymentHandoffStaysPendingAndNeverReportsSuccess(): void
    {
        $handoff = new PendingPaymentHandoff($this->logger);

        self::assertSame(HandoffResult::Pending, $handoff->handOff('approval-1', 'parent', 'child', 'event-1', 3));
        self::assertSame(HandoffResult::Pending, $handoff->handOff('approval-2', 'parent', 'child', 'event-2', 0));
        self::assertCount(2, $this->logger->messages);
    }

    #[Group('AC-46')]
    public function testTokensCannotBeSpentAndTheBalanceIsZero(): void
    {
        $spend = new UnavailableTokenSpend($this->logger);

        self::assertSame(SpendResult::Unavailable, $spend->spend('parent', 5, 'approval-1'));
        self::assertCount(1, $this->logger->messages);
        self::assertSame(0, (new ZeroTokenBalance())->balanceOf('anyone'));
    }

    #[Group('AC-41')]
    public function testTheChildIsRegisteredOnlyWhenThePaymentCapabilityReportsSuccess(): void
    {
        foreach ([[HandoffResult::Succeeded, true], [HandoffResult::Failed, false], [HandoffResult::Pending, false]] as [$result, $registered]) {
            $payments = new class ($result) implements PurchaseHandoff {
                public function __construct(private readonly HandoffResult $result) {}

                public function handOff(string $approvalRequestId, string $parentUserId, string $playerProfileId, string $eventRef, int $tokens, ?int $amountMinor = null, ?string $currency = null): HandoffResult
                {
                    return $this->result;
                }
            };
            $registrations = new class implements EventRegistrations {
                public int $registered = 0;

                public function register(string $eventRef, string $playerProfileId): bool
                {
                    $this->registered++;

                    return true;
                }

                public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int
                {
                    return 0;
                }
            };

            $outcome = (new ApprovedPurchaseHandoff($payments, $registrations))->execute('a', 'p', 'c', 'e', 2);

            self::assertSame($result, $outcome);
            self::assertSame($registered ? 1 : 0, $registrations->registered, $result->value);
        }
    }

    #[Group('AC-41')]
    public function testWithTheStubsAnApprovedPurchaseRegistersNobody(): void
    {
        $registrations = new class implements EventRegistrations {
            public int $registered = 0;

            public function register(string $eventRef, string $playerProfileId): bool
            {
                $this->registered++;

                return true;
            }

            public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int
            {
                return 0;
            }
        };

        $outcome = (new ApprovedPurchaseHandoff(new PendingPaymentHandoff($this->logger), $registrations))->execute('a', 'p', 'c', 'e', 2);

        self::assertSame(HandoffResult::Pending, $outcome);
        self::assertSame(0, $registrations->registered);
    }

    public function testTheContainerResolvesEveryPortToItsStub(): void
    {
        $container = (require __DIR__ . '/../../../config/container.php')();

        self::assertInstanceOf(EmptyEventCatalog::class, $container->get(EventCatalog::class));
        self::assertInstanceOf(NoopEventRegistrations::class, $container->get(EventRegistrations::class));
        self::assertInstanceOf(PendingPaymentHandoff::class, $container->get(PurchaseHandoff::class));
        self::assertInstanceOf(UnavailableTokenSpend::class, $container->get(TokenSpend::class));
        self::assertInstanceOf(ZeroTokenBalance::class, $container->get(TokenBalance::class));
        self::assertInstanceOf(ApprovedPurchaseHandoff::class, $container->get(ApprovedPurchaseHandoff::class));
    }
}
