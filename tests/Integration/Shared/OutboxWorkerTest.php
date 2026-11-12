<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Shared\Domain\FrozenClock;
use App\Shared\Infrastructure\Mail\EmailMessage;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use App\Shared\Infrastructure\Mail\InMemoryMailSender;
use App\Shared\Infrastructure\Notification\NotificationService;
use App\Shared\Infrastructure\Outbox\EmailOutboxHandler;
use App\Shared\Infrastructure\Outbox\JobOutboxHandler;
use App\Shared\Infrastructure\Outbox\OutboxHandler;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Outbox\PdoOutbox;
use App\Shared\Infrastructure\Outbox\PurgeOutboxTask;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\View\TwigFactory;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[Group('AC-86')]
final class OutboxWorkerTest extends IntegrationTestCase
{
    private InMemoryMailSender $mail;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new InMemoryMailSender();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2030-03-01 10:00:00+00'));
    }

    private function twig(): Environment
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/templates');
        $twig->setLoader(new ArrayLoader([
            'email/ping.html.twig' => '{% block subject %}Hello {{ name }}{% endblock %}<p>Hi {{ name }}</p>',
            'email/ping.text.twig' => 'Hi {{ name }}',
        ]));

        return $twig;
    }

    private function worker(PDO $pdo, ?JobOutboxHandler $jobs = null, ?OutboxHandler $email = null): OutboxWorker
    {
        return new OutboxWorker(
            $pdo,
            new TransactionManager($pdo),
            $this->clock,
            [
                'email' => $email ?? new EmailOutboxHandler(new EmailRenderer($this->twig()), $this->mail),
                'job' => $jobs ?? new JobOutboxHandler(),
            ],
            new NullLogger(),
        );
    }

    public function testRolledBackBusinessTransactionLeavesNoMail(): void
    {
        $outbox = new PdoOutbox($this->pdo, $this->clock);
        $tx = new TransactionManager($this->pdo);
        try {
            $tx->run(function () use ($outbox): void {
                $outbox->enqueueEmail(new EmailMessage('ping', 'a@example.com', ['name' => 'Ann']));
                throw new RuntimeException('business failure');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->worker($this->pdo)->runOnce());
        self::assertSame([], $this->mail->sent);
    }

    public function testCommittedEmailIsRenderedAndSentOnce(): void
    {
        $id = (new PdoOutbox($this->pdo, $this->clock))->enqueueEmail(new EmailMessage('ping', 'a@example.com', ['name' => '<Ann>']));
        $worker = $this->worker($this->pdo);

        self::assertSame(1, $worker->runOnce());
        self::assertSame(0, $worker->runOnce());
        self::assertCount(1, $this->mail->sent);
        $sent = $this->mail->sent[0];
        self::assertSame('a@example.com', $sent->to);
        self::assertSame('Hello <Ann>', $sent->subject);
        self::assertStringContainsString('&lt;Ann&gt;', $sent->html);
        self::assertSame('Hi <Ann>', $sent->text);
        self::assertSame($id . '@outbox.local', $sent->messageId);
        self::assertSame('done', $this->scalar('SELECT status FROM outbox WHERE id = :id', ['id' => $id]));
    }

    public function testFailedHandlerIsRetriedUpToFiveAttemptsWithBackoff(): void
    {
        $id = (new PdoOutbox($this->pdo, $this->clock))->enqueueJob('test.flaky', ['x' => 1]);
        $calls = 0;
        $jobs = new JobOutboxHandler();
        $jobs->register('test.flaky', function (OutboxMessage $m) use (&$calls): void {
            ++$calls;
            throw new RuntimeException('smtp down');
        });
        $worker = $this->worker($this->pdo, $jobs);

        self::assertSame(1, $worker->runOnce());
        self::assertSame(0, $worker->runOnce(), 'backoff: not due again immediately');
        self::assertSame(1, $calls);

        for ($i = 0; $i < 10; ++$i) {
            $this->clock->advance('2 days');
            $worker->runOnce();
        }

        self::assertSame(OutboxWorker::MAX_ATTEMPTS, $calls);
        self::assertSame('failed', $this->scalar('SELECT status FROM outbox WHERE id = :id', ['id' => $id]));
        self::assertSame(OutboxWorker::MAX_ATTEMPTS, (int) $this->scalar('SELECT attempts FROM outbox WHERE id = :id', ['id' => $id]));
        self::assertSame('smtp down', $this->scalar('SELECT last_error FROM outbox WHERE id = :id', ['id' => $id]));
    }

    public function testFailedHandlerDatabaseWorkIsRolledBackButRetrySucceeds(): void
    {
        $this->pdo->exec('CREATE TEMP TABLE probe (n int)');
        (new PdoOutbox($this->pdo, $this->clock))->enqueueJob('test.partial');
        $attempt = 0;
        $jobs = new JobOutboxHandler();
        $jobs->register('test.partial', function () use (&$attempt): void {
            ++$attempt;
            // the worker holds no transaction around a handler: this handler opens its own
            (new TransactionManager($this->pdo))->run(function () use ($attempt): void {
                $this->pdo->exec('INSERT INTO probe VALUES (1)');
                if ($attempt === 1) {
                    throw new RuntimeException('first try fails');
                }
            });
        });
        $worker = $this->worker($this->pdo, $jobs);
        $worker->runOnce();
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM probe'));
        $this->clock->advance('1 hour');
        $worker->runOnce();
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM probe'));
    }

    public function testJobWithoutRegisteredHandlerFailsVisibly(): void
    {
        $id = (new PdoOutbox($this->pdo, $this->clock))->enqueueJob('test.unknown');
        $this->worker($this->pdo)->runOnce();
        self::assertStringContainsString('No handler registered', (string) $this->scalar('SELECT last_error FROM outbox WHERE id = :id', ['id' => $id]));
    }

    public function testNotificationServiceWritesInAppRowAndQueuesEmailTogether(): void
    {
        $userId = (string) $this->scalar("INSERT INTO users (email, name, role) VALUES ('n@example.com', 'N', 'player') RETURNING id");
        $service = new NotificationService($this->pdo, new PdoOutbox($this->pdo, $this->clock));
        $service->notify($userId, 'approval.requested', 'Approval needed', 'Body', ['k' => 'v'], new EmailMessage('ping', 'n@example.com', ['name' => 'N']));

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM notifications WHERE user_id = :u', ['u' => $userId]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND status = 'pending'"));
    }

    #[Group('AC-98')]
    public function testSentEmailRowKeepsNoAddressNameLinkOrContext(): void
    {
        $id = (new PdoOutbox($this->pdo, $this->clock))->enqueueEmail(new EmailMessage('ping', 'secret.person@example.com', ['name' => 'Sam Secret', 'link' => 'https://x.test/reset/TOKEN123']));
        $this->worker($this->pdo)->runOnce();

        $row = $this->row('SELECT status, topic, attempts, processed_at, payload::text AS payload, last_error FROM outbox WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('done', $row['status']);
        self::assertSame('ping', $row['topic']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['processed_at']);
        self::assertSame('{}', $row['payload']);
        foreach (['secret.person', 'Sam', 'TOKEN123', 'https://'] as $leak) {
            self::assertStringNotContainsString($leak, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    #[Group('AC-98')]
    public function testEmailRowThatFailedForGoodIsScrubbedToo(): void
    {
        $id = (new PdoOutbox($this->pdo, $this->clock))->enqueueEmail(new EmailMessage('ping', 'secret.person@example.com', ['name' => 'Sam']));
        $email = new class implements OutboxHandler {
            public function handle(OutboxMessage $message): void
            {
                throw new RuntimeException('Email "secret.person@example.com" rejected');
            }
        };
        $worker = $this->worker($this->pdo, null, $email);
        for ($i = 0; $i < 10; ++$i) {
            $worker->runOnce();
            $this->clock->advance('2 days');
        }

        $row = $this->row('SELECT status, payload::text AS payload, last_error, processed_at FROM outbox WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('{}', $row['payload']);
        self::assertStringNotContainsString('secret.person', (string) $row['last_error']);
        self::assertNotNull($row['processed_at']);
    }

    #[Group('AC-98')]
    public function testPurgeDeletesOldFinishedRowsAndKeepsRecentAndPendingOnes(): void
    {
        $this->pdo->exec("INSERT INTO outbox (kind, topic, status, processed_at) VALUES
            ('email', 'old-done', 'done', '2030-02-20 10:00:00+00'),
            ('email', 'old-failed', 'failed', '2030-02-20 10:00:00+00'),
            ('email', 'recent-done', 'done', '2030-02-27 10:00:00+00'),
            ('email', 'old-pending', 'pending', NULL),
            ('job', 'old-processing', 'processing', NULL)");
        $this->pdo->exec("UPDATE outbox SET created_at = '2030-01-01 00:00:00+00' WHERE topic LIKE 'old-%'");

        $task = new PurgeOutboxTask($this->pdo, $this->clock);
        self::assertSame('outbox:purge', $task->name());
        $task->run();

        $left = $this->pdo->query("SELECT topic FROM outbox WHERE topic IN ('old-done','old-failed','recent-done','old-pending','old-processing') ORDER BY topic");
        self::assertNotFalse($left);
        self::assertSame(['old-pending', 'old-processing', 'recent-done'], $left->fetchAll(PDO::FETCH_COLUMN));
    }

    #[Group('AC-41')]
    public function testHandlersRunWithNoOpenTransactionAndEachRowIsFinalisedOnItsOwn(): void
    {
        $conn = self::connect();
        $topic = 'test.notx.' . bin2hex(random_bytes(4));
        $this->commitRows($conn, $topic, 2);
        try {
            $open = [];
            $jobs = new JobOutboxHandler();
            $jobs->register($topic, function () use ($conn, &$open): void {
                $open[] = $conn->inTransaction();
            });
            self::assertSame(2, $this->worker($conn, $jobs)->runOnce());
            self::assertSame([false, false], $open);
        } finally {
            $this->deleteRows($topic);
        }
    }

    #[Group('AC-41')]
    public function testACrashAfterTheThirdOfFiveRowsLeavesThemDoneAndTheRestAreReclaimedAfterTheLease(): void
    {
        $conn = self::connect();
        $topic = 'test.crash.' . bin2hex(random_bytes(4));
        $this->commitRows($conn, $topic, 5);
        try {
            $handled = [];
            $jobs = new JobOutboxHandler();
            $jobs->register($topic, function (OutboxMessage $m) use (&$handled): void {
                $handled[] = $m->id;
                if (count($handled) === 4) {
                    throw new RuntimeException('handler blew up');
                }
            });
            // the failure bookkeeping dies too: the process is "killed" while handling row 4
            $dying = new class extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    throw new \Error('killed');
                }
            };
            $crashing = new OutboxWorker($conn, new TransactionManager($conn), $this->clock, ['job' => $jobs, 'email' => new JobOutboxHandler()], $dying);
            try {
                $crashing->runOnce();
                self::fail('the worker should have died');
            } catch (\Error $e) {
                self::assertSame('killed', $e->getMessage());
            }

            $statuses = $this->statusesByTopic($topic);
            self::assertSame(['done', 'done', 'done', 'processing', 'processing'], $statuses);
            self::assertCount(4, $handled);

            // another worker: leases still valid, nothing to claim
            $other = self::connect();
            $again = [];
            $jobs2 = new JobOutboxHandler();
            $jobs2->register($topic, function (OutboxMessage $m) use (&$again): void {
                $again[] = $m->id;
            });
            $second = $this->worker($other, $jobs2);
            self::assertSame(0, $second->runOnce());

            // the lease expires: only the two unfinished rows are picked up, none of the three done ones
            $this->clock->advance((OutboxWorker::LEASE_SECONDS + 1) . ' seconds');
            self::assertSame(2, $second->runOnce());
            self::assertSame(array_slice($handled, 3, 1), array_slice($again, 0, 1), 'the row that was being handled is retried');
            self::assertSame([], array_intersect(array_slice($handled, 0, 3), $again), 'rows 1-3 are never sent again');
            self::assertSame(['done', 'done', 'done', 'done', 'done'], $this->statusesByTopic($topic));
        } finally {
            $this->deleteRows($topic);
        }
    }

    /** @return list<string> */
    private function statusesByTopic(string $topic): array
    {
        $stmt = self::connect()->prepare('SELECT status FROM outbox WHERE topic = :t ORDER BY available_at, id');
        $stmt->execute(['t' => $topic]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function commitRows(PDO $conn, string $topic, int $count): void
    {
        $at = $this->clock->now();
        for ($i = 0; $i < $count; ++$i) {
            $stmt = $conn->prepare("INSERT INTO outbox (kind, topic, available_at) VALUES ('job', :t, :at)");
            $stmt->execute(['t' => $topic, 'at' => $at->modify('-' . ($count - $i) . ' seconds')->format('Y-m-d H:i:sP')]);
        }
    }

    private function deleteRows(string $topic): void
    {
        $del = self::connect()->prepare('DELETE FROM outbox WHERE topic = :t');
        $del->execute(['t' => $topic]);
    }

    public function testTwoWorkersNeverProcessTheSameRow(): void
    {
        $a = self::connect();
        $b = self::connect();
        $a->beginTransaction();
        $idRow = $a->query("INSERT INTO outbox (kind, topic) VALUES ('job', 'test.race') RETURNING id");
        self::assertNotFalse($idRow);
        $id = (string) $idRow->fetchColumn();
        $a->commit();

        try {
            $secondWorkerClaimed = null;
            $handled = 0;
            $jobsB = new JobOutboxHandler();
            $jobsB->register('test.race', function () use (&$handled): void {
                ++$handled;
            });
            $workerB = $this->worker($b, $jobsB);

            $jobsA = new JobOutboxHandler();
            $jobsA->register('test.race', function () use (&$handled, $workerB, &$secondWorkerClaimed): void {
                ++$handled;
                // while worker A holds the row lock, worker B runs concurrently on its own connection
                $secondWorkerClaimed = $workerB->runOnce();
            });
            $workerA = $this->worker($a, $jobsA);

            self::assertSame(1, $workerA->runOnce());
            self::assertSame(0, $secondWorkerClaimed, 'worker B must skip the locked row');
            self::assertSame(1, $handled, 'exactly one handler execution');
            self::assertSame(0, $workerB->runOnce(), 'row is done, nothing left to claim');
        } finally {
            $cleanup = self::connectAsAppRw();
            $del = $cleanup->prepare('DELETE FROM outbox WHERE id = :id');
            $del->execute(['id' => $id]);
        }
    }
}
