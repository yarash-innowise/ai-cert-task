<?php

declare(strict_types=1);

namespace Tests\Http\TenantIsolation;

use League\Route\Router;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Tests\Http\AppHttpTestCase;

/**
 * AC-10: no data of one Trainer's organization is ever visible to another Trainer. Walks every
 * registered tenant route with the other organization's identifiers and expects 404 (not 403, which
 * would confirm the record exists); fails when a route is not classified in TenantRouteRegistry.
 */
#[Group('AC-10')]
final class TenantIsolationTest extends AppHttpTestCase
{
    private string $orgA;
    private string $orgB;

    protected function extraRoutes(): array
    {
        return [TenantFixtureRoutes::register(...)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $trainerA = $this->createUser('trainer', 'a@example.com', name: 'Trainer A');
        $trainerB = $this->createUser('trainer', 'b@example.com', name: 'Trainer B');
        $this->orgA = $this->organization($trainerA, 'Org A');
        $this->orgB = $this->organization($trainerB, 'Org B');
    }

    private function organization(string $ownerId, string $name): string
    {
        return (string) $this->scalar('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n) RETURNING id', ['o' => $ownerId, 'n' => $name]);
    }

    /**
     * @param list<callable(Router, ContainerInterface): void> $extra
     * @return list<string>
     */
    private function routeTable(array $extra = []): array
    {
        $router = new RecordingRouter();
        $routes = require __DIR__ . '/../../../config/routes.php';
        $routes($router, $this->container);
        TenantFixtureRoutes::register($router, $this->container);
        foreach ($extra as $register) {
            $register($router, $this->container);
        }

        return $router->table();
    }

    #[Group('AC-9')]
    public function testEveryRegisteredRouteIsClassifiedAsTenantOrNonTenant(): void
    {
        self::assertSame([], TenantRouteRegistry::unclassified($this->routeTable()), 'Register new routes in TenantRouteRegistry (tenant or non-tenant).');
    }

    public function testTheHarnessFailsWhenANewRouteIsNotRegistered(): void
    {
        $table = $this->routeTable([static function (Router $router): void {
            $router->get('/trainer/schedules/{id}', static fn() => null);
        }]);

        self::assertSame(['GET /trainer/schedules/{id}'], TenantRouteRegistry::unclassified($table));
    }

    public function testEveryRegisteredTenantRouteStillExistsInTheRouteTable(): void
    {
        $table = $this->routeTable();

        foreach (array_keys(TenantRouteRegistry::tenantRoutes()) as $key) {
            self::assertContains($key, $table, "{$key} is listed as a tenant route but is not registered");
        }
    }

    public function testATrainerGetsNotFoundNotForbiddenForEveryTenantRouteOfAnotherOrganization(): void
    {
        $this->cookies = [];
        $this->login('a@example.com');

        $checked = 0;
        foreach (TenantRouteRegistry::tenantRoutes() as $key => $foreignParams) {
            [$method, $template] = explode(' ', $key, 2);
            $path = $this->fill($template, $foreignParams($this->orgB));

            $response = $method === 'GET' ? $this->get($path) : $this->post($path);

            self::assertSame(404, $response->getStatusCode(), "{$key} with a foreign organization must answer 404");
            self::assertStringNotContainsString('Org B', $this->body($response), $key);
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testTheSameRoutesWorkForTheTrainersOwnOrganization(): void
    {
        $this->cookies = [];
        $this->login('a@example.com');

        foreach (TenantRouteRegistry::tenantRoutes() as $key => $foreignParams) {
            [$method, $template] = explode(' ', $key, 2);
            $path = $this->fill($template, $foreignParams($this->orgA));

            $response = $method === 'GET' ? $this->get($path) : $this->post($path);

            self::assertSame(200, $response->getStatusCode(), "{$key} for the own organization");
            self::assertStringContainsString('Org A', $this->body($response));
        }
    }

    public function testAnUnknownResourceLooksTheSameAsAForeignOne(): void
    {
        $this->cookies = [];
        $this->login('a@example.com');

        $foreign = $this->get('/_fixture/organizations/' . $this->orgB);
        $unknown = $this->get('/_fixture/organizations/00000000-0000-7000-8000-000000000000');
        $malformed = $this->get('/_fixture/organizations/not-a-uuid');

        self::assertSame(404, $foreign->getStatusCode());
        $normalize = fn(\Psr\Http\Message\ResponseInterface $r): string => (string) preg_replace('/nonce="[^"]+"/', 'nonce=""', $this->body($r));
        self::assertSame($normalize($foreign), $normalize($unknown));
        self::assertSame($normalize($foreign), $normalize($malformed));
    }

    public function testACoachAndAPlayerAlsoCannotSeeAnOrganizationTheyAreNotIn(): void
    {
        foreach (['coach', 'player'] as $role) {
            $this->cookies = [];
            $this->signInAs($role);

            self::assertSame(404, $this->get('/_fixture/organizations/' . $this->orgA)->getStatusCode(), $role);
        }
    }

    /** @param array<string, string> $params */
    private function fill(string $template, array $params): string
    {
        foreach ($params as $name => $value) {
            $template = str_replace('{' . $name . '}', $value, $template);
        }

        return $template;
    }
}
