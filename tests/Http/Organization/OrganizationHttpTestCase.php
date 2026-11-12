<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use Tests\Http\Onboarding\OnboardingHttpTestCase;

/** Fixtures for the Trainer-side tests: coaches, invitations and rosters. The frozen clock is 2030-03-01 10:00 UTC. */
abstract class OrganizationHttpTestCase extends OnboardingHttpTestCase
{
    protected const COACH_PASSWORD = 'a long coach password';

    /** @return array{string, string} the Trainer's user id and organization id, signed in as that Trainer */
    protected function trainerSignedIn(string $business = 'Ace Tennis Academy'): array
    {
        return $this->signInAsTrainerWithOrganization($business);
    }

    /** @param array<string, string> $extra */
    protected function invite(string $email = 'coach@example.com', array $extra = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/trainer/coaches/invite', ['email' => $email, ...$extra]);
    }

    /** The token in the link of the newest invitation email queued for the address. */
    protected function tokenFor(string $email): string
    {
        $row = $this->row(
            "SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'coach-invitation' AND payload->>'to' = :to ORDER BY created_at DESC, id DESC LIMIT 1",
            ['to' => $email],
        );
        self::assertIsArray($row, 'an invitation email was queued for ' . $email);
        /** @var array{context: array{accept_url: string}} $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $path = (string) parse_url($payload['context']['accept_url'], PHP_URL_PATH);
        self::assertStringStartsWith('/invitations/', $path);

        return substr($path, strlen('/invitations/'));
    }

    protected function coachUser(string $email = 'coach@example.com', string $name = 'Casey Coach'): string
    {
        return $this->createUser('coach', $email, self::COACH_PASSWORD, name: $name);
    }

    protected function signOut(): void
    {
        $this->cookies = [];
    }

    protected function signInAsCoach(string $email): void
    {
        $this->cookies = [];
        $this->login($email, self::COACH_PASSWORD);
    }

    protected function activeMemberships(string $coachUserId): int
    {
        return (int) $this->scalar("SELECT count(*) FROM coach_memberships WHERE coach_user_id = :u AND status = 'active'", ['u' => $coachUserId]);
    }

    protected function invitationStatusOf(string $email): string
    {
        $row = $this->row(
            "SELECT accepted_at, expires_at, revoked_at FROM coach_invitations WHERE email = :e AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1",
            ['e' => $email],
        );
        self::assertIsArray($row);
        if ($row['accepted_at'] !== null) {
            return 'accepted';
        }

        return new \DateTimeImmutable((string) $row['expires_at']) <= $this->clock->now() ? 'expired' : 'pending';
    }
}
