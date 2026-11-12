<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Shared\Domain\FrozenClock;
use App\Shared\Infrastructure\Scheduler\ScheduledTask;
use App\Shared\Infrastructure\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Tests\Integration\IntegrationTestCase;

#[Group('AC-88')]
final class SchedulerLockTest extends IntegrationTestCase
{
    private function task(string $name, \Closure $run, int $interval = 60): ScheduledTask
    {
        return new class ($name, $run, $interval) implements ScheduledTask {
            public function __construct(private string $name, private \Closure $run, private int $interval) {}

            public function name(): string
            {
                return $this->name;
            }

            public function intervalSeconds(): int
            {
                return $this->interval;
            }

            public function run(): void
            {
                ($this->run)();
            }
        };
    }

    public function testSecondSchedulerSkipsTaskWhileFirstHoldsTheLock(): void
    {
        $clock = new FrozenClock();
        $executions = 0;
        $second = null;
        $conn2 = self::connect();
        $schedulerB = new Scheduler($conn2, $clock, [$this->task('test.locked', function () use (&$executions): void {
            ++$executions;
        })], new NullLogger());

        $schedulerA = new Scheduler($this->pdo, $clock, [$this->task('test.locked', function () use (&$executions, $schedulerB, &$second): void {
            ++$executions;
            $second = $schedulerB->tick();
        })], new NullLogger());

        self::assertSame(['test.locked'], $schedulerA->tick());
        self::assertSame([], $second, 'concurrent scheduler must not run the locked task');
        self::assertSame(1, $executions);

        // lock released after the run: B may now run it
        self::assertSame(['test.locked'], $schedulerB->tick());
        self::assertSame(2, $executions);
    }

    public function testTaskRunsOnlyWhenIntervalElapsed(): void
    {
        $clock = new FrozenClock();
        $count = 0;
        $scheduler = new Scheduler($this->pdo, $clock, [$this->task('test.interval', function () use (&$count): void {
            ++$count;
        }, 60)], new NullLogger());

        $scheduler->tick();
        $clock->advance('30 seconds');
        $scheduler->tick();
        self::assertSame(1, $count);
        $clock->advance('31 seconds');
        $scheduler->tick();
        self::assertSame(2, $count);
    }

    public function testFailingTaskIsLoggedAndDoesNotStopOthers(): void
    {
        $ran = false;
        $scheduler = new Scheduler($this->pdo, new FrozenClock(), [
            $this->task('test.bad', function (): void {
                throw new \RuntimeException('boom');
            }),
            $this->task('test.good', function () use (&$ran): void {
                $ran = true;
            }),
        ], new NullLogger());

        self::assertSame(['test.good'], $scheduler->tick());
        self::assertTrue($ran);
    }
}
