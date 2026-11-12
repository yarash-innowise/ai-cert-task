<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Admin\Application\SweepImpersonations;
use App\Admin\Infrastructure\SweepImpersonationsTask;
use App\Shared\Infrastructure\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\Group;

/** The `impersonations:sweep` task closes impersonations nobody ended (AC-54, AC-55). The frozen clock is 2030-03-01 10:00 UTC. */
final class ImpersonationSweepTest extends ImpersonationTestCase
{
    private function sweep(): int
    {
        $sweep = $this->container->get(SweepImpersonations::class);
        self::assertInstanceOf(SweepImpersonations::class, $sweep);

        return $sweep->execute();
    }

    /** An open impersonation as the middleware would have left it; returns its id. */
    private function openImpersonation(string $startedAt, string $lastActivity, bool $withSession): string
    {
        $id = (string) $this->scalar(
            'INSERT INTO impersonations (admin_user_id, target_user_id, started_at, last_activity_at) VALUES (:a, :t, :s, :l) RETURNING id',
            ['a' => $this->admin, 't' => $this->coach, 's' => $startedAt, 'l' => $lastActivity],
        );
        if ($withSession) {
            $this->pdo->prepare('INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, last_seen_at, impersonation_id, impersonator_user_id) VALUES (:h, :u, :c, :t, :t, :i, :a)')
                ->execute(['h' => 'sweep-' . $id, 'u' => $this->coach, 'c' => 'csrf', 't' => $this->clock->now()->format('Y-m-d H:i:s.uP'), 'i' => $id, 'a' => $this->admin]);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function existing(string $sql, array $params = []): array
    {
        return $this->row($sql, $params) ?? self::fail('row missing');
    }

    #[Group('AC-54')]
    public function testAnImpersonationPastOneHourIsClosedAsTimeoutAtStartPlusOneHourWhenTheAdminWasActiveUntilThen(): void
    {
        $id = $this->openImpersonation('2030-03-01 08:00:00+00', '2030-03-01 08:59:30+00', true);

        self::assertSame(1, $this->sweep());

        $row = $this->existing('SELECT ended_at, end_reason FROM impersonations WHERE id = :i', ['i' => $id]);
        self::assertSame('timeout', $row['end_reason']);
        self::assertEquals(new \DateTimeImmutable('2030-03-01 08:59:30+00'), new \DateTimeImmutable((string) $row['ended_at']), 'the earlier of start + 1 h and the last activity');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended' AND payload->>'impersonation_id' = :i AND payload->>'end_reason' = 'timeout'", ['i' => $id]));
    }

    #[Group('AC-54')]
    public function testTheEndNeverExceedsOneHourEvenWhenActivityRanLonger(): void
    {
        $id = $this->openImpersonation('2030-03-01 07:00:00+00', '2030-03-01 09:30:00+00', true);

        $this->sweep();

        $row = $this->existing('SELECT ended_at FROM impersonations WHERE id = :i', ['i' => $id]);
        self::assertEquals(new \DateTimeImmutable('2030-03-01 08:00:00+00'), new \DateTimeImmutable((string) $row['ended_at']));
    }

    #[Group('AC-55')]
    public function testAnImpersonationWhoseSessionIsGoneIsClosedAsSessionExpiredAtItsLastActivity(): void
    {
        $id = $this->openImpersonation('2030-03-01 09:30:00+00', '2030-03-01 09:41:00+00', false);

        self::assertSame(1, $this->sweep());

        $row = $this->existing('SELECT ended_at, end_reason FROM impersonations WHERE id = :i', ['i' => $id]);
        self::assertSame('session_expired', $row['end_reason']);
        self::assertEquals(new \DateTimeImmutable('2030-03-01 09:41:00+00'), new \DateTimeImmutable((string) $row['ended_at']));
    }

    #[Group('AC-54')]
    public function testARunningImpersonationWithinTheHourIsLeftAlone(): void
    {
        $id = $this->openImpersonation('2030-03-01 09:30:00+00', '2030-03-01 09:55:00+00', true);

        self::assertSame(0, $this->sweep());

        self::assertNull($this->existing('SELECT ended_at FROM impersonations WHERE id = :i', ['i' => $id])['ended_at']);
    }

    #[Group('AC-54')]
    public function testRunningTheSweepAgainChangesNothing(): void
    {
        $this->openImpersonation('2030-03-01 08:00:00+00', '2030-03-01 08:10:00+00', true);
        $this->sweep();
        $audit = (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended'");

        self::assertSame(0, $this->sweep());
        self::assertSame($audit, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended'"));
    }

    #[Group('AC-54')]
    public function testASessionThatOutlivedTheSweptImpersonationReturnsToTheAdminAtItsNextRequest(): void
    {
        $this->startAsCoach();
        $this->clock->advance('90 minutes');
        $this->sweep();
        self::assertSame('timeout', $this->impersonationRow()['end_reason']);
        $audit = (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended'");

        $response = $this->get('/coach');

        self::assertSame('/admin/users?notice=impersonation_timeout', $this->location($response));
        self::assertSame($this->admin, $this->sessionRow()['user_id']);
        self::assertSame($audit, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended'"), 'the end is not logged twice');
        self::assertSame(200, $this->get('/admin/users')->getStatusCode());
    }

    #[Group('AC-54')]
    public function testTheSweepIsARegisteredScheduledTask(): void
    {
        $tasks = (array) $this->container->get('scheduler.tasks');
        self::assertContains(SweepImpersonationsTask::class, $tasks);
        $task = $this->container->get(SweepImpersonationsTask::class);
        self::assertInstanceOf(SweepImpersonationsTask::class, $task);
        self::assertSame('impersonations:sweep', $task->name());
        $id = $this->openImpersonation('2030-03-01 08:00:00+00', '2030-03-01 08:10:00+00', true);

        $task->run();

        self::assertSame('timeout', $this->existing('SELECT end_reason FROM impersonations WHERE id = :i', ['i' => $id])['end_reason']);
        self::assertInstanceOf(Scheduler::class, $this->container->get(Scheduler::class));
    }
}
