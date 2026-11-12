<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Admin\Infrastructure\UserSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

/**
 * Index review (AC-88): EXPLAIN ANALYZE of the hot queries on 10,000 users, 20,000 failed sign-in attempts
 * and 10,000 sessions. The SQL mirrors PdoUserDirectoryQuery, PdoLoginThrottle and PdoSessionRepository;
 * the plans are written to var/perf/plans.txt. Run with `--group perf`.
 */
#[Group('perf'), Group('AC-88')]
final class QueryPlanTest extends IntegrationTestCase
{
    private string $report = '';

    protected function setUp(): void
    {
        parent::setUp();
        (new UserSeeder($this->pdo))->seed(10000);
        $this->pdo->exec("INSERT INTO login_attempts (email, ip, succeeded, attempted_at) SELECT 'u' || (n % 500) || '@example.com', '10.0.' || (n % 200) || '.1', false, now() - (n || ' seconds')::interval FROM generate_series(1, 20000) n");
        $this->pdo->exec("INSERT INTO sessions (token_hash, user_id, csrf_token) SELECT md5(n::text), (SELECT id FROM users LIMIT 1), 'x' FROM generate_series(1, 10000) n");
        $this->pdo->exec('ANALYZE users');
        $this->pdo->exec('ANALYZE login_attempts');
        $this->pdo->exec('ANALYZE sessions');
    }

    protected function tearDown(): void
    {
        $directory = __DIR__ . '/../../var/perf';
        if ($this->report !== '' && (is_dir($directory) || @mkdir($directory, 0775, true))) {
            @file_put_contents($directory . '/plans.txt', $this->report, FILE_APPEND);
        }
        parent::tearDown();
    }

    /** @return array{string, float} the plan text and its execution time in ms */
    private function plan(string $label, string $sql): array
    {
        $statement = $this->pdo->query('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF) ' . $sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $plan = implode("\n", array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
        $this->report .= "== {$label}\n{$plan}\n\n";
        self::assertSame(1, preg_match('/Execution Time: ([0-9.]+) ms/', $plan, $matches), $label);

        return [$plan, (float) ($matches[1] ?? 0)];
    }

    public function testTheUsersListAndSearchStayFastAtTenThousandUsers(): void
    {
        $join = 'FROM users u LEFT JOIN organizations o ON o.owner_user_id = u.id';
        $cases = [
            'users list, last page' => "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, o.business_name {$join} ORDER BY u.created_at DESC, u.id LIMIT 50 OFFSET 9950",
            'users count' => "SELECT count(*) {$join}",
            'users search' => "SELECT u.id {$join} WHERE (u.name ILIKE '%seed user 77%' OR u.email ILIKE '%seed user 77%') ORDER BY u.created_at DESC, u.id LIMIT 50",
        ];
        foreach ($cases as $label => $sql) {
            [, $ms] = $this->plan($label, $sql);
            self::assertLessThan(200.0, $ms, $label);
        }
        [$plan] = $this->plan('users role+status filter', "SELECT u.id {$join} WHERE u.role = 'coach' AND u.status = 'active' ORDER BY u.created_at DESC, u.id LIMIT 50 OFFSET 100");
        self::assertStringContainsString('users_role_status_created', $plan);
    }

    public function testSignInThrottleAndSessionLookupsUseIndexes(): void
    {
        [$byEmail] = $this->plan('throttle by email', "SELECT attempted_at FROM login_attempts WHERE email = 'u7@example.com' AND succeeded = false AND attempted_at > now() - interval '15 minutes' ORDER BY attempted_at DESC LIMIT 5");
        [$byIp] = $this->plan('throttle by ip', "SELECT attempted_at FROM login_attempts WHERE ip = '10.0.7.1' AND succeeded = false AND attempted_at > now() - interval '15 minutes' ORDER BY attempted_at DESC LIMIT 5");
        [$session] = $this->plan('session by token hash', "SELECT * FROM sessions WHERE token_hash = md5('77')");

        self::assertStringContainsString('login_attempts_email', $byEmail);
        self::assertStringContainsString('login_attempts_ip', $byIp);
        self::assertStringContainsString('sessions_pkey', $session);
        self::assertStringNotContainsString('Seq Scan', $byEmail . $byIp . $session);
    }
}
