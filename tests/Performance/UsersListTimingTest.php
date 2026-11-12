<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Admin\Infrastructure\UserSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\AppHttpTestCase;
use Tests\Support\PerfLog;

/** Users list of 10,000 users loads in under 3 seconds with pagination (AC-88). Run with `--group perf`. */
#[Group('perf'), Group('AC-88')]
final class UsersListTimingTest extends AppHttpTestCase
{
    public function testTheUsersListOfTenThousandUsersLoadsInUnderThreeSeconds(): void
    {
        $this->signInAs('super_admin');
        self::assertSame(10000, (new UserSeeder($this->pdo))->seed(10000));
        $this->pdo->exec('ANALYZE users');

        foreach (['/admin/users', '/admin/users?page=200', '/admin/users?q=seed+user+77', '/admin/users?role=coach&status=active&page=3'] as $uri) {
            $started = hrtime(true);
            $response = $this->get($uri);
            $seconds = (hrtime(true) - $started) / 1e9;
            PerfLog::record('users list ' . $uri, $seconds, '10,001 users');

            self::assertSame(200, $response->getStatusCode(), $uri);
            self::assertLessThan(3.0, $seconds, sprintf('%s took %.3f s', $uri, $seconds));
        }
        self::assertStringContainsString('10001 users found', $this->body($this->get('/admin/users')));
    }
}
