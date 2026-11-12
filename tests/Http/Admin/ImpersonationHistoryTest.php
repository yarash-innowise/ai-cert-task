<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class ImpersonationHistoryTest extends ImpersonationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    #[Group('AC-56')]
    #[DataProvider('nonAdminRoles')]
    public function testOnlyASuperAdminOpensTheReport(string $role): void
    {
        $this->signInAs($role, 'nonadmin@example.com');

        self::assertSame(403, $this->get('/admin/impersonations')->getStatusCode());
    }

    #[Group('AC-56')]
    public function testAnEmptyReportSaysSo(): void
    {
        $html = $this->body($this->get('/admin/impersonations'));

        self::assertStringContainsString('Impersonation history', $html);
        self::assertStringContainsString('No impersonation has been started yet.', $html);
    }

    #[Group('AC-55'), Group('AC-56')]
    public function testRowsMatchTheLogWithAdminTargetStartEndDurationReasonAndActions(): void
    {
        $this->startAsCoach();
        $this->clock->advance('7 minutes 30 seconds');
        $this->post('/profile', ['name' => 'Cora Renamed']);
        $this->post('/impersonation/exit');
        $this->clock->advance('1 hour');
        $this->impersonate($this->kid);
        $this->clock->advance('2 minutes');
        $this->post('/impersonation/exit');

        $html = $this->body($this->get('/admin/impersonations'));

        self::assertStringContainsString('2 impersonations', $html);
        $first = (string) strpos($html, 'Cora Renamed');
        $second = (string) strpos($html, 'Kid Login');
        self::assertGreaterThan($second, $first, 'newest first: the Kid Login impersonation is listed before the Coach one');
        self::assertMatchesRegularExpression('#<th scope="row">Ada Admin</th>\s*<td>Cora Renamed</td>\s*<td>2030-03-01 10:00:00 UTC</td>\s*<td>2030-03-01 10:07:30 UTC</td>\s*<td>00:07:30</td>\s*<td>Exited by the administrator</td>\s*<td>1</td>#', $html);
        self::assertMatchesRegularExpression('#<td>Kid Login</td>\s*<td>2030-03-01 11:07:30 UTC</td>\s*<td>2030-03-01 11:09:30 UTC</td>\s*<td>00:02:00</td>\s*<td>Exited by the administrator</td>\s*<td>0</td>#', $html);
    }

    #[Group('AC-56')]
    public function testTheDurationIsEndMinusStartAndAnOpenOneIsInProgress(): void
    {
        $this->startAsCoach();

        $html = $this->body($this->get('/admin/impersonations', [], $this->adminJarWhileImpersonating()));
        self::assertStringContainsString('In progress', $html);
        self::assertStringContainsString('Still open', $html);

        $this->pdo->exec("UPDATE impersonations SET ended_at = started_at + interval '3725 seconds', end_reason = 'timeout'");
        $html = $this->body($this->get('/admin/impersonations', [], $this->adminJarWhileImpersonating()));
        self::assertStringContainsString('01:02:05', $html);
        self::assertStringContainsString('Ended automatically after 1 hour', $html);
    }

    /**
     * The report is an admin page: read it with a second admin session so the impersonating one stays as it is.
     *
     * @return array<string, string>
     */
    private function adminJarWhileImpersonating(): array
    {
        $own = $this->cookies;
        $jar = $this->signInWith(self::ADMIN_EMAIL);
        $this->cookies = $own;

        return $jar;
    }

    #[Group('AC-56')]
    public function testTheReportIsPagedNewestFirst(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO impersonations (admin_user_id, target_user_id, started_at, last_activity_at, ended_at, end_reason) VALUES (:a, :t, :s, :s, :s::timestamptz + interval '60 seconds', 'manual')");
        for ($i = 0; $i < 27; ++$i) {
            $stmt->execute(['a' => $this->admin, 't' => $this->coach, 's' => sprintf('2029-01-%02d 08:00:00+00', $i + 1)]);
        }

        $one = $this->body($this->get('/admin/impersonations'));
        $two = $this->body($this->get('/admin/impersonations?page=2'));
        $past = $this->body($this->get('/admin/impersonations?page=99'));

        self::assertStringContainsString('27 impersonations. Page 1 of 2.', $one);
        self::assertSame(25, substr_count($one, '<td>Cora Coach</td>'));
        self::assertStringContainsString('2029-01-27', $one);
        self::assertSame(2, substr_count($two, '<td>Cora Coach</td>'));
        self::assertStringContainsString('2029-01-01', $two);
        self::assertStringContainsString('Page 2 of 2', $past, 'a page past the end shows the last page');
    }

    #[Group('AC-56')]
    public function testADeletedUserStillAppearsAsDeletedUser(): void
    {
        $this->startAsCoach();
        $this->post('/impersonation/exit');
        $this->deactivate($this->coach);
        $this->deleteUser($this->coach);

        $html = $this->body($this->get('/admin/impersonations'));

        self::assertStringContainsString('<td>Deleted User</td>', $html);
        self::assertStringNotContainsString('Cora Coach', $html);
    }
}
