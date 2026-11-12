<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class UsersListTest extends AppHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAs('super_admin', 'admin@example.com', 'Ada Admin');
    }

    /** @return list<string> the names in the first column of the list page, in order */
    private function names(string $uri): array
    {
        $response = $this->get($uri);
        self::assertSame(200, $response->getStatusCode(), $uri);
        preg_match_all('#<tbody>.*?</tbody>#s', $this->body($response), $tbody);
        preg_match_all('#<th scope="row">(.*?)</th>#', $tbody[0][0] ?? '', $m);

        return array_map(static fn(string $n): string => html_entity_decode($n), $m[1]);
    }

    private function seed(int $count, string $prefix = 'Zed'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $stmt = $this->pdo->prepare("INSERT INTO users (email, name, role, status, created_at) VALUES (:e, :n, 'player', 'active', :t)");
            $stmt->execute([
                'e' => strtolower($prefix) . $i . '@example.com',
                'n' => sprintf('%s %03d', $prefix, $i),
                't' => $this->clock->now()->modify("-{$i} minutes")->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    #[Group('AC-80')]
    public function testSearchMatchesNameOrEmailInAnyLetterCaseAndTreatsWildcardsAsText(): void
    {
        $this->createUser('player', 'robin.hood@example.com', name: 'Robin Hood');
        $this->createUser('player', 'maid@example.com', name: 'Marian 100% Maid');

        self::assertSame(['Robin Hood'], $this->names('/admin/users?q=ROBIN'));
        self::assertSame(['Robin Hood'], $this->names('/admin/users?q=hood%40example'));
        self::assertSame(['Marian 100% Maid'], $this->names('/admin/users?q=' . urlencode('100%')));
        self::assertSame([], $this->names('/admin/users?q=' . urlencode('%%%')));
        self::assertSame([], $this->names('/admin/users?q=nobody-here'));
    }

    #[Group('AC-80')]
    public function testEachFilterNarrowsTheList(): void
    {
        $this->createUser('coach', 'c@example.com', name: 'Carl Coach');
        $this->createUser('player', 'p@example.com', name: 'Pia Player');
        $this->createUser('player', 'i@example.com', name: 'Ivan Inactive', status: 'inactive');

        self::assertSame(['Carl Coach'], $this->names('/admin/users?role=coach'));
        self::assertEqualsCanonicalizing(['Pia Player', 'Ivan Inactive'], $this->names('/admin/users?role=player'));
        self::assertSame(['Ivan Inactive'], $this->names('/admin/users?status=inactive'));
        self::assertSame(['Ivan Inactive'], $this->names('/admin/users?role=player&status=inactive'));
        self::assertEqualsCanonicalizing(['Pia Player', 'Ivan Inactive', 'Carl Coach', 'Ada Admin'], $this->names('/admin/users?role=bogus&status=bogus'));
    }

    #[Group('AC-80')]
    public function testTheOrganizationFilterListsTheTrainerOfThatOrganization(): void
    {
        $this->post('/admin/users/new', ['business_name' => 'Ace Academy', 'trainer_name' => 'Tina Trainer', 'email' => 'tina@example.com', 'phone' => '+1 415 555 2671']);
        $this->post('/admin/users/new', ['business_name' => 'Baseline Club', 'trainer_name' => 'Ben Trainer', 'email' => 'ben@example.com', 'phone' => '+1 415 555 2672']);
        $orgId = $this->scalar("SELECT id FROM organizations WHERE business_name = 'Ace Academy'");
        self::assertIsString($orgId);

        self::assertSame(['Tina Trainer'], $this->names('/admin/users?organization=' . $orgId));
        $page = $this->body($this->get('/admin/users'));
        self::assertStringContainsString('Ace Academy', $page);
        self::assertStringContainsString('Baseline Club', $page);
    }

    #[Group('AC-80')]
    public function testPaginationShowsTwentyFivePerPageNewestFirstAndClampsBoundaries(): void
    {
        $this->seed(60);
        $base = '/admin/users?q=zed';

        $first = $this->names($base);
        self::assertCount(25, $first);
        self::assertSame('Zed 001', $first[0]);
        self::assertSame('Zed 025', $first[24]);
        self::assertSame('Zed 026', $this->names($base . '&page=2')[0]);
        $third = $this->names($base . '&page=3');
        self::assertCount(10, $third);
        self::assertSame('Zed 060', $third[9]);
        // past the end shows the last page; zero, negative and junk show the first
        self::assertSame($third, $this->names($base . '&page=99'));
        self::assertSame($first, $this->names($base . '&page=0'));
        self::assertSame($first, $this->names($base . '&page=-4'));
        self::assertSame($first, $this->names($base . '&page=abc'));

        $html = $this->body($this->get($base . '&page=2'));
        self::assertStringContainsString('60 users found. Page 2 of 3.', $html);
        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertStringContainsString('q=zed&amp;role=&amp;status=&amp;organization=&amp;page=3', $html);
        self::assertStringNotContainsString('rel="next"', $this->body($this->get($base . '&page=3')));
        self::assertStringNotContainsString('rel="prev"', $this->body($this->get($base)));
    }

    #[Group('AC-16')]
    #[Group('AC-80')]
    public function testANewlyCreatedTrainerAppearsActiveAndInactiveAndDeletedAreMarked(): void
    {
        $this->post('/admin/users/new', ['business_name' => 'Ace Academy', 'trainer_name' => 'Tina Trainer', 'email' => 'tina@example.com', 'phone' => '+1 415 555 2671']);
        $this->createUser('player', 'i@example.com', name: 'Ivan Inactive', status: 'inactive');
        $this->createUser('player', 'd@example.com', name: 'Dora Deleted', status: 'deleted');

        $row = static fn(string $html, string $name): string => (string) (preg_match('#<tr>\s*<th scope="row">' . preg_quote($name, '#') . '</th>.*?</tr>#s', $html, $m) === 1 ? $m[0] : '');
        $html = $this->body($this->get('/admin/users'));

        self::assertStringContainsString('>Active</span>', $row($html, 'Tina Trainer'));
        self::assertStringContainsString('Ace Academy', $row($html, 'Tina Trainer'));
        self::assertStringContainsString('>Inactive</span>', $row($html, 'Ivan Inactive'));
        self::assertStringContainsString('>Deleted</span>', $row($html, 'Dora Deleted'));
    }

    #[Group('AC-80')]
    public function testUserDetailsAreEscapedInTheList(): void
    {
        $this->createUser('player', 'x@example.com', name: '<script>alert(1)</script>');

        $html = $this->body($this->get('/admin/users'));

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Group('AC-9')]
    #[Group('AC-80')]
    #[DataProvider('nonAdminRoles')]
    public function testOnlyASuperAdminCanOpenTheUsersTool(string $role): void
    {
        $this->cookies = [];
        $this->signInAs($role);

        self::assertSame(403, $this->get('/admin/users')->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    #[Group('AC-80')]
    public function testAGuestIsSentToSignIn(): void
    {
        $this->cookies = [];

        $response = $this->get('/admin/users');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($response));
    }
}
