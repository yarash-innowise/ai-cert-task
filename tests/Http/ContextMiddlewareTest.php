<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextOptions;
use App\Shared\Domain\ContextSource;
use App\Shared\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\TenantIsolation\TenantFixtureRoutes;

#[Group('AC-9')]
#[Group('AC-10')]
final class ContextMiddlewareTest extends AppHttpTestCase
{
    private const SECOND_ORG = '0190aaaa-0000-7000-8000-00000000000b';
    private const PROFILE = '0190aaaa-0000-7000-8000-0000000000f1';

    protected function extraRoutes(): array
    {
        return [TenantFixtureRoutes::register(...)];
    }

    private function trainerWithOrganization(string $email = 'a@example.com'): string
    {
        $id = $this->createUser('trainer', $email, name: 'Trainer');
        $this->pdo->prepare('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n)')->execute(['o' => $id, 'n' => 'Org of ' . $email]);

        return (string) $this->scalar('SELECT id FROM organizations WHERE owner_user_id = :o', ['o' => $id]);
    }

    private function context(): string
    {
        $response = $this->get(TenantFixtureRoutes::CONTEXT);
        self::assertSame(200, $response->getStatusCode());

        return $this->body($response);
    }

    /** Adds a source that gives every user a fixed extra option (second organization with a profile). */
    private function withExtraOption(): void
    {
        $extra = new class implements ContextSource {
            public function optionsFor(string $userId, string $role): array
            {
                return [new ContextOption(OrganizationId::fromString('0190aaaa-0000-7000-8000-00000000000b'), '0190aaaa-0000-7000-8000-0000000000f1')];
            }
        };
        $owned = $this->container->get(\App\Organization\Infrastructure\OwnedOrganizationContextSource::class);
        \assert($this->container instanceof \DI\Container && $owned instanceof ContextSource);
        $this->container->set(ContextOptions::class, new ContextOptions([$owned, $extra]));
        $this->kernel = $this->kernel(...$this->extraRoutes());
    }

    private function chooseContext(string $organizationId, ?string $profileId): void
    {
        $this->pdo->prepare('UPDATE sessions SET active_context = :c')->execute(['c' => json_encode(['organization_id' => $organizationId, 'profile_id' => $profileId], JSON_THROW_ON_ERROR)]);
    }

    public function testATrainerActsInTheirOwnOrganization(): void
    {
        $org = $this->trainerWithOrganization();
        $this->login('a@example.com');

        self::assertSame("org={$org};profile=none", $this->context());
    }

    public function testAUserWithoutAnOrganizationHasNoContext(): void
    {
        foreach (['super_admin', 'coach', 'player'] as $role) {
            $this->cookies = [];
            $this->signInAs($role);

            self::assertSame('org=none;profile=none', $this->context(), $role);
        }
    }

    public function testTheRememberedChoiceIsUsedWhenItIsStillValid(): void
    {
        $this->trainerWithOrganization();
        $this->withExtraOption();
        $this->login('a@example.com');
        self::assertStringStartsWith('org=', $this->context(), 'first valid context by default');

        $this->chooseContext(self::SECOND_ORG, self::PROFILE);

        self::assertSame('org=' . self::SECOND_ORG . ';profile=' . self::PROFILE, $this->context());
    }

    public function testAnInvalidRememberedChoiceFallsBackToTheFirstValidContext(): void
    {
        $org = $this->trainerWithOrganization();
        $this->login('a@example.com');
        // another organization the trainer is not a member of, and a malformed value
        $this->chooseContext('0190aaaa-0000-7000-8000-0000000000ff', null);
        self::assertSame("org={$org};profile=none", $this->context());

        $this->chooseContext('not-a-uuid', null);
        self::assertSame("org={$org};profile=none", $this->context());

        $this->chooseContext($org, self::PROFILE);
        self::assertSame("org={$org};profile=none", $this->context(), 'a profile that is not valid in that organization is not accepted');
    }

    public function testMembershipIsValidatedOnEveryRequest(): void
    {
        $org = $this->trainerWithOrganization();
        $this->login('a@example.com');
        $this->chooseContext($org, null);
        self::assertSame("org={$org};profile=none", $this->context());

        $this->pdo->exec("UPDATE organizations SET status = 'inactive'");

        self::assertSame('org=none;profile=none', $this->context());
    }

    public function testNoOrganizationDataIsReachableWithoutAContext(): void
    {
        $org = $this->trainerWithOrganization();
        $this->cookies = [];
        $this->signInAs('coach');

        self::assertSame(404, $this->get('/_fixture/organizations/' . $org)->getStatusCode());
    }
}
