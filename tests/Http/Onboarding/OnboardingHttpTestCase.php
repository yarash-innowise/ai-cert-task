<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use Tests\Http\Family\FamilyHttpTestCase;

abstract class OnboardingHttpTestCase extends FamilyHttpTestCase
{
    /**
     * Signs in as the Trainer of a fresh organization.
     *
     * @return array{string, string} user id and organization id
     */
    protected function signInAsTrainerWithOrganization(string $business = 'Ace Tennis Academy'): array
    {
        $email = 'trainer' . bin2hex(random_bytes(3)) . '@example.com';
        $trainer = $this->createUser('trainer', $email, name: 'Tina Trainer');
        $organization = (string) $this->scalar('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n) RETURNING id', ['o' => $trainer, 'n' => $business]);
        $this->cookies = [];
        $this->login($email);

        return [$trainer, $organization];
    }

    protected function codeOf(string $organizationId): ?string
    {
        $code = $this->scalar('SELECT code FROM share_links WHERE organization_id = :o AND active', ['o' => $organizationId]);

        return is_string($code) ? $code : null;
    }
}
