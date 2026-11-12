<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class RoleDashboardTest extends AppHttpTestCase
{
    private const DASHBOARDS = [
        'super_admin' => ['/admin', 'Super Admin dashboard'],
        'trainer' => ['/trainer', 'Trainer dashboard'],
        'coach' => ['/coach', 'Coach dashboard'],
        'player' => ['/family', 'Player and parent dashboard'],
    ];

    /** @return iterable<string, array{string}> */
    public static function roles(): iterable
    {
        foreach (array_keys(self::DASHBOARDS) as $role) {
            yield $role => [$role];
        }
    }

    #[Group('AC-1')]
    #[DataProvider('roles')]
    public function testEachRoleLandsOnItsOwnDashboardAfterSignIn(string $role): void
    {
        $this->createUser($role, 'who@example.com');
        [$path, $title] = self::DASHBOARDS[$role];

        $login = $this->login('who@example.com');
        self::assertSame($path, $this->location($login));

        $page = $this->get($this->location($login));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString("<h1>{$title}</h1>", $this->body($page));
        self::assertSame(1, substr_count($this->body($page), '<h1'));
    }

    #[Group('AC-9')]
    #[DataProvider('roles')]
    public function testARoleCannotReachAnotherRolesDashboard(string $role): void
    {
        $this->createUser($role, 'who@example.com');
        $this->login('who@example.com');

        foreach (self::DASHBOARDS as $otherRole => [$path, $title]) {
            if ($otherRole === $role) {
                continue;
            }
            $response = $this->get($path);
            self::assertSame(403, $response->getStatusCode(), "{$role} -> {$path}");
            self::assertStringNotContainsString($title, $this->body($response));
        }
    }

    #[Group('AC-9')]
    public function testEveryUserHasExactlyOneRole(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO users (email, name, role) VALUES ('x@example.com', 'X', 'trainer,coach')");
    }

    #[Group('AC-1')]
    public function testSignedInUsersSkipTheLoginPage(): void
    {
        $this->createUser('coach', 'who@example.com');
        $this->login('who@example.com');

        $response = $this->get('/login');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/coach', $this->location($response));
    }

    #[Group('AC-9')]
    public function testLayoutShowsTheSignedInUserAndASignOutForm(): void
    {
        $this->createUser('trainer', 'who@example.com', name: 'Tina Trainer');
        $this->login('who@example.com');

        $html = $this->body($this->get('/trainer'));
        self::assertStringContainsString('Tina Trainer', $html);
        self::assertStringContainsString('action="/logout"', $html);
    }
}
