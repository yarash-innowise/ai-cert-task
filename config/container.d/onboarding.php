<?php

declare(strict_types=1);

use App\Integration\Application\RegistrationPrefillWriter;
use App\Integration\Application\SendShareLinkByEmail as SendShareLinkByEmailPort;
use App\Onboarding\Application\SendShareLinkByEmail;
use App\Onboarding\Application\StoreRegistrationPrefill;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Onboarding\Infrastructure\PdoRegistrationPrefillRepository;
use App\Onboarding\Infrastructure\PdoShareLinkRepository;

use function DI\autowire;

/** Onboarding module bindings. */
return [
    ShareLinkRepository::class => autowire(PdoShareLinkRepository::class),
    RegistrationPrefillRepository::class => autowire(PdoRegistrationPrefillRepository::class),
    // Ports consumed by Epic-08 (AC-85)
    RegistrationPrefillWriter::class => autowire(StoreRegistrationPrefill::class),
    SendShareLinkByEmailPort::class => autowire(SendShareLinkByEmail::class),
];
