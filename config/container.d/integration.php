<?php

declare(strict_types=1);

use App\Integration\Application\EventCatalog;
use App\Integration\Application\EventRegistrations;
use App\Integration\Application\PurchaseHandoff;
use App\Integration\Application\RsvpCancellations;
use App\Integration\Application\TokenBalance;
use App\Integration\Application\TokenSpend;
use App\Integration\Infrastructure\EmptyEventCatalog;
use App\Integration\Infrastructure\NoopEventRegistrations;
use App\Integration\Infrastructure\NoopRsvpCancellations;
use App\Integration\Infrastructure\PendingPaymentHandoff;
use App\Integration\Infrastructure\UnavailableTokenSpend;
use App\Integration\Infrastructure\ZeroTokenBalance;

use function DI\autowire;

/**
 * Integration ports and their Epic-01 stand-ins. Epic-02 / Epic-05 replace a binding here.
 * RegistrationPrefillWriter and SendShareLinkByEmail are bound by the Onboarding module.
 */
return [
    EventCatalog::class => autowire(EmptyEventCatalog::class),
    EventRegistrations::class => autowire(NoopEventRegistrations::class),
    RsvpCancellations::class => autowire(NoopRsvpCancellations::class),
    PurchaseHandoff::class => autowire(PendingPaymentHandoff::class),
    TokenSpend::class => autowire(UnavailableTokenSpend::class),
    TokenBalance::class => autowire(ZeroTokenBalance::class),
];
