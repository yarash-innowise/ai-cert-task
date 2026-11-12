<?php

declare(strict_types=1);

namespace Tests\Integration\Organization;

use App\Organization\Domain\CoachInvitationToken;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\IntegrationTestCase;

/**
 * Two PHP processes with their own database connections accept at the same moment. These tests
 * COMMIT their fixtures (a rolled-back transaction would hide them from the other processes) and
 * remove them again in tearDown, as the database owner, bypassing the append-only audit trigger.
 */
#[Group('AC-26'), Group('AC-59')]
final class ConcurrentAcceptTest extends TestCase
{
    private PDO $pdo;
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $organizationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $connect = new \ReflectionMethod(IntegrationTestCase::class, 'connectAsAppRw');
        $pdo = $connect->invoke(null);
        assert($pdo instanceof PDO);
        $this->pdo = $pdo;
    }

    protected function tearDown(): void
    {
        // Only this cleanup uses the owner: the restricted role cannot bypass the append-only audit trigger.
        $owner = new \ReflectionMethod(IntegrationTestCase::class, 'connectAsOwner');
        $ownerPdo = $owner->invoke(null);
        assert($ownerPdo instanceof PDO);
        $this->pdo = $ownerPdo;
        $this->pdo->exec('SET session_replication_role = replica');
        $users = $this->ids($this->userIds);
        $organizations = $this->ids($this->organizationIds);
        if ($organizations !== "''") {
            $this->pdo->exec("DELETE FROM audit_log WHERE organization_id IN ({$organizations})");
            $this->pdo->exec("DELETE FROM coach_memberships WHERE organization_id IN ({$organizations})");
            $this->pdo->exec("DELETE FROM coach_invitations WHERE organization_id IN ({$organizations})");
            $this->pdo->exec("DELETE FROM organizations WHERE id IN ({$organizations})");
        }
        if ($users !== "''") {
            $this->pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
            $this->pdo->exec("DELETE FROM users WHERE id IN ({$users})");
        }
        $this->pdo->exec('SET session_replication_role = DEFAULT');
        parent::tearDown();
    }

    private function value(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return $stmt->fetchColumn();
    }

    /** @param list<string> $ids */
    private function ids(array $ids): string
    {
        return implode(',', array_map(fn(string $id): string => $this->pdo->quote($id), $ids)) ?: "''";
    }

    private function user(string $role, string $email): string
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (email, name, role, password_hash, email_verified_at) VALUES (:e, 'Fixture', :r, 'x', now()) RETURNING id");
        $stmt->execute(['e' => $email, 'r' => $role]);

        return $this->userIds[] = (string) $stmt->fetchColumn();
    }

    private function organization(): string
    {
        $owner = $this->user('trainer', 'trainer-' . bin2hex(random_bytes(4)) . '@concurrent.test');
        $stmt = $this->pdo->prepare("INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, 'Concurrent Academy') RETURNING id");
        $stmt->execute(['o' => $owner]);

        return $this->organizationIds[] = (string) $stmt->fetchColumn();
    }

    /** @return string the token */
    private function invitation(string $organization, string $email): string
    {
        $token = CoachInvitationToken::generate();
        $stmt = $this->pdo->prepare(
            "INSERT INTO coach_invitations (organization_id, email, token_hash, created_by, created_at, expires_at)
             VALUES (:o, :e, :h, (SELECT owner_user_id FROM organizations WHERE id = :o), now(), now() + interval '7 days')",
        );
        $stmt->execute(['o' => $organization, 'e' => $email, 'h' => CoachInvitationToken::hash($token)]);

        return $token;
    }

    /**
     * Starts one process per argument list at the same moment and returns their outputs.
     *
     * @param list<list<string>> $runs
     * @return list<string>
     */
    private function raceAccepts(array $runs): array
    {
        return $this->race(array_map(static fn(array $args): array => ['accept-invitation.php', ...$args], $runs));
    }

    /**
     * Starts one process per run (script name in tests/Support, then its arguments) at the same moment.
     *
     * @param list<list<string>> $runs
     * @return list<string>
     */
    private function race(array $runs): array
    {
        $processes = [];
        foreach ($runs as $run) {
            $script = array_shift($run);
            $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/Support/' . $script], $run);
            $env = ['APP_ENV' => 'testing'] + getenv();
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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

    public function testTwoGuestsOpeningTheSameLinkAtOnceCreateExactlyOneCoach(): void
    {
        $organization = $this->organization();
        $email = 'racer-' . bin2hex(random_bytes(4)) . '@concurrent.test';
        $token = $this->invitation($organization, $email);

        $outputs = $this->raceAccepts([[$token, 'guest'], [$token, 'guest']]);

        self::assertSame(['accepted', 'refused:already_used'], $outputs);
        $this->userIds[] = (string) $this->value("SELECT id FROM users WHERE email = '{$email}'");
        self::assertSame(1, (int) $this->value("SELECT count(*) FROM users WHERE email = '{$email}'"));
        self::assertSame(1, (int) $this->value("SELECT count(*) FROM coach_memberships WHERE organization_id = '{$organization}'"));
    }

    public function testTwoTrainersInvitationsAcceptedAtOnceLeaveTheCoachActiveUnderExactlyOne(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();
        $email = 'shared-' . bin2hex(random_bytes(4)) . '@concurrent.test';
        $coach = $this->user('coach', $email);
        $tokenA = $this->invitation($orgA, $email);
        $tokenB = $this->invitation($orgB, $email);

        $outputs = $this->raceAccepts([[$tokenA, 'user:' . $coach], [$tokenB, 'user:' . $coach]]);

        self::assertSame(['accepted', 'refused:coach_already_active'], $outputs);
        self::assertSame(1, (int) $this->value("SELECT count(*) FROM coach_memberships WHERE coach_user_id = '{$coach}' AND status = 'active'"));
        // the refused invitation was rolled back, so it is still open
        self::assertSame(1, (int) $this->value("SELECT count(*) FROM coach_invitations WHERE email = '{$email}' AND accepted_at IS NULL"));
    }

    /** F-09: an acceptance and the deletion of the Trainer race for the organization row; the loser is refused. */
    public function testAnAcceptanceRacingTheTrainerDeletionNeverEndsWithADeletedTrainerAndAnActiveCoach(): void
    {
        $admin = $this->user('super_admin', 'admin-' . bin2hex(random_bytes(4)) . '@concurrent.test');
        $seen = [];
        for ($round = 0; $round < 6; $round++) {
            $organization = $this->organization();
            $trainer = (string) $this->value("SELECT owner_user_id FROM organizations WHERE id = '{$organization}'");
            $email = 'racer-' . bin2hex(random_bytes(4)) . '@concurrent.test';
            $coach = $this->user('coach', $email);
            $token = $this->invitation($organization, $email);

            $outputs = $this->race([['accept-invitation.php', $token, 'user:' . $coach], ['delete-trainer.php', $trainer, $admin]]);

            $trainerStatus = (string) $this->value("SELECT status FROM users WHERE id = '{$trainer}'");
            $active = (int) $this->value("SELECT count(*) FROM coach_memberships WHERE organization_id = '{$organization}' AND status = 'active'");
            self::assertFalse($trainerStatus === 'deleted' && $active > 0, 'deleted Trainer with an active coach: ' . implode(' | ', $outputs));
            foreach ($outputs as $output) {
                self::assertStringNotContainsString('error:', $output);
            }
            $seen[] = $trainerStatus === 'deleted' ? 'deleted' : 'kept';
        }
        self::assertNotSame([], $seen);
    }
}
