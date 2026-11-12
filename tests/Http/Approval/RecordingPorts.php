<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Integration\Application\EventRegistrations;
use App\Integration\Application\HandoffResult;
use App\Integration\Application\PurchaseHandoff;
use App\Integration\Application\RsvpCancellations;
use App\Integration\Application\SpendResult;
use App\Integration\Application\TokenSpend;

/** Stand-ins for the Epic-02 / Epic-05 ports that record every call and answer what the test says. */
final class RecordingPorts
{
    /** @var list<array{string, string}> register(event, profile) calls */
    public array $registered = [];
    /** @var list<array{string, string}> cancelUpcomingFor(profile, organization) calls */
    public array $cancelled = [];
    /** @var list<array<string, mixed>> handOff() calls */
    public array $handoffs = [];
    /** @var list<array{string, int, string}> spend() calls */
    public array $spends = [];
    /** @var list<array{string, string}> cancel(event, profile) calls */
    public array $rsvpCancelled = [];
    public bool $cancelSucceeds = true;
    public HandoffResult $handoffResult = HandoffResult::Pending;
    public SpendResult $spendResult = SpendResult::Spent;
    public bool $registerSucceeds = true;
    public ?\Throwable $handoffThrows = null;

    public function registrations(): EventRegistrations
    {
        $ports = $this;

        return new class ($ports) implements EventRegistrations {
            public function __construct(private readonly RecordingPorts $ports) {}

            public function register(string $eventRef, string $playerProfileId): bool
            {
                $this->ports->registered[] = [$eventRef, $playerProfileId];

                return $this->ports->registerSucceeds;
            }

            public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int
            {
                $this->ports->cancelled[] = [$playerProfileId, (string) $organizationId];

                return 0;
            }
        };
    }

    public function cancellations(): RsvpCancellations
    {
        $ports = $this;

        return new class ($ports) implements RsvpCancellations {
            public function __construct(private readonly RecordingPorts $ports) {}

            public function cancel(string $eventRef, string $playerProfileId): bool
            {
                $this->ports->rsvpCancelled[] = [$eventRef, $playerProfileId];

                return $this->ports->cancelSucceeds;
            }
        };
    }

    public function handoff(): PurchaseHandoff
    {
        $ports = $this;

        return new class ($ports) implements PurchaseHandoff {
            public function __construct(private readonly RecordingPorts $ports) {}

            public function handOff(string $approvalRequestId, string $parentUserId, string $playerProfileId, string $eventRef, int $tokens, ?int $amountMinor = null, ?string $currency = null): HandoffResult
            {
                $this->ports->handoffs[] = compact('approvalRequestId', 'parentUserId', 'playerProfileId', 'eventRef', 'tokens', 'amountMinor', 'currency');
                if ($this->ports->handoffThrows !== null) {
                    throw $this->ports->handoffThrows;
                }

                return $this->ports->handoffResult;
            }
        };
    }

    public function tokens(): TokenSpend
    {
        $ports = $this;

        return new class ($ports) implements TokenSpend {
            public function __construct(private readonly RecordingPorts $ports) {}

            public function spend(string $payerUserId, int $tokens, string $reference): SpendResult
            {
                $this->ports->spends[] = [$payerUserId, $tokens, $reference];

                return $this->ports->spendResult;
            }
        };
    }
}
