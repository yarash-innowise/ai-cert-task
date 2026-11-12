<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

/**
 * Real PHP processes with their own connections sign in at the same moment: a parallel burst must not
 * get more than five attempts through per email or per IP (AC-3). Attempts are COMMITTED by the
 * children, so the rows are removed again in tearDown.
 */
#[Group('AC-3')]
final class LoginThrottleRaceTest extends \PHPUnit\Framework\TestCase
{
    private const PARALLEL = 10;

    private PDO $pdo;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        $connect = new \ReflectionMethod(IntegrationTestCase::class, 'connectAsAppRw');
        $pdo = $connect->invoke(null);
        assert($pdo instanceof PDO);
        $this->pdo = $pdo;
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE email LIKE :p')->execute(['p' => '%' . $this->suffix . '@race.test']);
        parent::tearDown();
    }

    /**
     * @param list<string> $emails one process per email
     * @return list<string> the outputs
     */
    private function burst(array $emails, string $ip): array
    {
        $startAt = microtime(true) + 1.5;
        $processes = [];
        foreach ($emails as $email) {
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/Support/login-attempt.php', $email, 'a wrong password', $ip, (string) $startAt];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['APP_ENV' => 'testing'] + getenv());
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $outputs[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        sort($outputs);

        return $outputs;
    }

    public function testParallelAttemptsOnOneEmailNeverExceedFive(): void
    {
        $email = 'same-' . $this->suffix . '@race.test';

        $outputs = $this->burst(array_fill(0, self::PARALLEL, $email), '198.51.100.' . random_int(1, 250));

        self::assertSame(array_merge(array_fill(0, 5, 'invalid'), array_fill(0, self::PARALLEL - 5, 'locked')), $this->ordered($outputs));
    }

    public function testParallelAttemptsFromOneIpAcrossManyEmailsNeverExceedFive(): void
    {
        $emails = [];
        for ($i = 0; $i < self::PARALLEL; ++$i) {
            $emails[] = "spray{$i}-" . $this->suffix . '@race.test';
        }

        $outputs = $this->burst($emails, '198.51.100.' . random_int(1, 250));

        self::assertSame(array_merge(array_fill(0, 5, 'invalid'), array_fill(0, self::PARALLEL - 5, 'locked')), $this->ordered($outputs));
    }

    /**
     * @param list<string> $outputs
     * @return list<string> invalid first, then locked
     */
    private function ordered(array $outputs): array
    {
        self::assertSame([], array_values(array_diff($outputs, ['invalid', 'locked'])), 'no process failed unexpectedly');
        usort($outputs, static fn(string $a, string $b): int => strcmp($a, $b));

        return $outputs;
    }
}
