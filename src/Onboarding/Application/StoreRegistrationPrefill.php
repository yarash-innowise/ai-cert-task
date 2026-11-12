<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Integration\Application\RegistrationPrefillWriter;
use App\Onboarding\Domain\PrefillToken;
use App\Onboarding\Domain\RegistrationPrefillRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\OrganizationId;

/**
 * The Onboarding implementation of the {@see RegistrationPrefillWriter} port (AC-85): keeps what a
 * person typed in a camp/evaluation form for 14 days under a one-time token. Only the known form
 * fields are kept, as bounded text; the token (never the data) goes into the link.
 */
final class StoreRegistrationPrefill implements RegistrationPrefillWriter
{
    public const LIFETIME = 'P14D';
    public const FIELDS = ['name', 'email', 'phone', 'player_for', 'player_name', 'birth_date', 'gender'];

    public function __construct(
        private readonly RegistrationPrefillRepository $prefills,
        private readonly Clock $clock,
    ) {}

    public function write(OrganizationId $organization, array $fields): string
    {
        $kept = [];
        foreach (self::FIELDS as $name) {
            $value = $fields[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $kept[$name] = mb_substr(trim($value), 0, 200);
            }
        }
        if (isset($kept['email'])) {
            $kept['email'] = mb_strtolower($kept['email']);
        }

        $token = PrefillToken::generate();
        $now = $this->clock->now();
        $this->prefills->add($organization, PrefillToken::hash($token), $kept, $now->add(new \DateInterval(self::LIFETIME)), $now);

        return $token;
    }
}
