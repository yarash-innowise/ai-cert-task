<?php

declare(strict_types=1);

namespace Tests\Integration\Approval;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTestCase;

/**
 * Two PHP processes with their own database connections decide the same request at the same moment
 * (two browser tabs, a double click on two connections). The fixtures are COMMITTED (a rolled-back
 * transaction would hide them from the other processes) and removed again in tearDown by the database
 * owner, which bypasses the append-only audit trigger for these test rows only.
 */
#[Group('AC-45')]
final class DecideRaceTest extends TestCase
{
    private PDO $pdo;
    private string $suffix;
    /** @var list<string> */
    private array $users = [];
    private string $organization = '';
    private string $profile = '';
    private string $parent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $connect = new \ReflectionMethod(IntegrationTestCase::class, 'connectAsAppRw');
        $pdo = $connect->invoke(null);
        assert($pdo instanceof PDO);
        $this->pdo = $pdo;
        $this->suffix = bin2hex(random_bytes(4));
        $trainer = $this->user('trainer', 'trainer');
        $this->parent = $this->user('player', 'parent');
        $this->organization = $this->value('INSERT INTO organizations (owner_user_id, business_name) VALUES (' . $this->pdo->quote($trainer) . ", 'Race Academy') RETURNING id");
        $this->profile = $this->value("INSERT INTO player_profiles (owner_user_id, kind, first_name, last_name, birth_date, gender) VALUES ('{$this->parent}', 'child', 'Race', 'Kid', '2018-01-01', 'other') RETURNING id");
    }

    protected function tearDown(): void
    {
        // Only this cleanup uses the owner: the restricted role cannot bypass the append-only audit trigger.
        $owner = new \ReflectionMethod(IntegrationTestCase::class, 'connectAsOwner');
        $ownerPdo = $owner->invoke(null);
        assert($ownerPdo instanceof PDO);
        $this->pdo = $ownerPdo;
        $this->pdo->exec('SET session_replication_role = replica');
        $users = implode(',', array_map(fn(string $id): string => $this->pdo->quote($id), $this->users)) ?: "''";
        $this->pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users}) OR organization_id = " . $this->pdo->quote($this->organization ?: '00000000-0000-7000-8000-000000000000'));
        $this->pdo->exec("DELETE FROM notifications WHERE user_id IN ({$users})");
        $this->pdo->exec("DELETE FROM outbox WHERE payload::text LIKE '%{$this->suffix}%' OR payload->>'request_id' IN (SELECT id::text FROM approval_requests WHERE organization_id = " . $this->pdo->quote($this->organization ?: '00000000-0000-7000-8000-000000000000') . ')');
        $this->pdo->exec('DELETE FROM approval_requests WHERE organization_id = ' . $this->pdo->quote($this->organization ?: '00000000-0000-7000-8000-000000000000'));
        $this->pdo->exec('DELETE FROM player_profiles WHERE id = ' . $this->pdo->quote($this->profile ?: '00000000-0000-7000-8000-000000000000'));
        $this->pdo->exec('DELETE FROM organizations WHERE id = ' . $this->pdo->quote($this->organization ?: '00000000-0000-7000-8000-000000000000'));
        $this->pdo->exec("DELETE FROM users WHERE id IN ({$users})");
        $this->pdo->exec('SET session_replication_role = DEFAULT');
        parent::tearDown();
    }

    private function value(string $sql): string
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return (string) $stmt->fetchColumn();
    }

    private function user(string $role, string $label): string
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (email, name, role, password_hash, email_verified_at) VALUES (:e, :n, :r, 'x', now()) RETURNING id");
        $stmt->execute(['e' => $label . '-' . $this->suffix . '@race.test', 'n' => ucfirst($label), 'r' => $role]);

        return $this->users[] = (string) $stmt->fetchColumn();
    }

    private function request(): string
    {
        $child = $this->user('player', 'kid');
        $stmt = $this->pdo->prepare(
            "INSERT INTO approval_requests (organization_id, child_profile_id, child_user_id, parent_user_id, kind, event_ref, amount_minor, payment_type, created_at, expires_at)
             VALUES (:o, :p, :c, :par, 'purchase', :e, 2500, 'usd', now(), now() + interval '48 hours') RETURNING id",
        );
        $stmt->execute(['o' => $this->organization, 'p' => $this->profile, 'c' => $child, 'par' => $this->parent, 'e' => '0190aaaa-0000-7000-8000-' . str_pad($this->suffix, 12, '0')]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $decisions one process per decision
     * @return list<string> the outputs, sorted
     */
    private function race(string $requestId, array $decisions): array
    {
        $processes = [];
        foreach ($decisions as $decision) {
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/Support/decide-request.php', $requestId, $this->parent, $decision];
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

    public function testTwoApprovalsAtTheSameMomentDecideOnceAndQueueOneHandoff(): void
    {
        $id = $this->request();

        $outputs = $this->race($id, ['approve', 'approve']);

        self::assertSame(['decided:approved', 'refused:conflict'], $outputs);
        self::assertSame('approved', $this->value("SELECT status FROM approval_requests WHERE id = '{$id}'"));
        self::assertSame('1', $this->value("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff' AND payload->>'request_id' = '{$id}'"));
        self::assertSame('2', $this->value("SELECT version FROM approval_requests WHERE id = '{$id}'"));
    }

    public function testAnApproveAndADenyAtTheSameMomentLeaveExactlyOneDecision(): void
    {
        $id = $this->request();

        $outputs = $this->race($id, ['approve', 'deny']);

        self::assertCount(2, $outputs);
        self::assertContains('refused:conflict', $outputs);
        $decided = array_values(array_diff($outputs, ['refused:conflict']));
        self::assertCount(1, $decided);
        $status = $this->value("SELECT status FROM approval_requests WHERE id = '{$id}'");
        self::assertSame('decided:' . $status, $decided[0]);
        $jobs = (int) $this->value("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff' AND payload->>'request_id' = '{$id}'");
        self::assertSame($status === 'approved' ? 1 : 0, $jobs, 'a hand-off exists only for an approval');
    }
}
