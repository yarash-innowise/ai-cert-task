<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Approval\Application\ExpireApprovals;
use App\Shared\Infrastructure\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\Group;

final class ExpireApprovalsTest extends ApprovalHttpTestCase
{
    private string $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->openRequest();
    }

    private function runJob(): int
    {
        $job = $this->container->get(ExpireApprovals::class);
        self::assertInstanceOf(ExpireApprovals::class, $job);

        return $job->execute();
    }

    private function statusOf(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $id]);
    }

    #[Group('AC-44')]
    public function testARequestIsUntouchedAt47HoursAnd59Minutes(): void
    {
        $this->clock->advance('47 hours 59 minutes');

        self::assertSame(0, $this->runJob());

        self::assertSame('pending', $this->statusOf($this->request));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_expired'"));
    }

    #[Group('AC-44')]
    public function testARequestWithoutParentActionIsDeniedAutomaticallyAt48Hours(): void
    {
        $this->clock->advance('48 hours');

        self::assertSame(1, $this->runJob());

        $row = $this->requestRow();
        self::assertSame('expired', $row['status']);
        self::assertNull($row['decided_by'], 'no parent decided it');
        self::assertSame(2, (int) $row['version']);
        self::assertSame([], $this->ports->registered);
        self::assertSame([], $this->ports->handoffs);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
    }

    #[Group('AC-44'), Group('AC-86')]
    public function testBothParentAndChildAreNotifiedInTheAppAndByEmail(): void
    {
        $this->clock->advance('48 hours');

        $this->runJob();

        foreach ([$this->parent => self::PARENT_EMAIL, $this->kid => self::KID_EMAIL] as $user => $email) {
            $note = $this->row("SELECT title, body FROM notifications WHERE kind = 'approval_expired' AND user_id = :u", ['u' => $user]);
            self::assertIsArray($note, 'in-app notice for ' . $email);
            self::assertStringContainsString('expired', (string) $note['title']);
            self::assertStringContainsString('48 hours', (string) $note['body']);
            self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE kind = 'email' AND topic = 'approval-outcome' AND payload->>'to' = :e", ['e' => $email]), 'email to ' . $email);
        }
        $this->signInAsKid();
        self::assertStringContainsString('Expired', $this->body($this->get('/family/requests')));
    }

    #[Group('AC-44')]
    public function testRunningTheJobTwiceChangesAndNotifiesNothingTheSecondTime(): void
    {
        $this->clock->advance('49 hours');

        self::assertSame(1, $this->runJob());
        self::assertSame(0, $this->runJob());

        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_expired'"));
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approval-outcome'"));
    }

    #[Group('AC-44')]
    public function testARequestWhereInformationWasAskedExpiresToo(): void
    {
        $this->pdo->prepare("UPDATE approval_requests SET status = 'info_requested' WHERE id = :i")->execute(['i' => $this->request]);
        $this->clock->advance('48 hours');

        self::assertSame(1, $this->runJob());
        self::assertSame('expired', $this->statusOf($this->request));
    }

    #[Group('AC-44'), Group('AC-45')]
    public function testDecidedRequestsAreNeverExpired(): void
    {
        $approved = $this->openRequest('rsvp', 'approved', self::EVENT_2, payment: 'none', amount: 0);
        $denied = $this->openRequest('purchase', 'denied', '0190aaaa-0000-7000-8000-0000000000e3');
        $done = $this->openRequest('purchase', 'completed', '0190aaaa-0000-7000-8000-0000000000e4');
        $this->clock->advance('72 hours');

        $this->runJob();

        self::assertSame('approved', $this->statusOf($approved));
        self::assertSame('denied', $this->statusOf($denied));
        self::assertSame('completed', $this->statusOf($done));
        self::assertSame('expired', $this->statusOf($this->request));
    }

    #[Group('AC-45')]
    public function testExpiryIsCorrectEvenWhenTheJobHasNotRun(): void
    {
        $this->clock->advance('48 hours');
        $this->touchSessions();
        $this->signInAsParent();

        $decision = $this->post('/family/approvals/' . $this->request . '/decision', ['decision' => 'approve']);

        self::assertSame(409, $decision->getStatusCode());
        self::assertStringContainsString('expired', $this->body($decision));
        self::assertSame('pending', $this->statusOf($this->request), 'the job has not run, yet nothing was approved');
        self::assertSame([], $this->ports->registered);
        $page = $this->body($this->get('/family/approvals/' . $this->request));
        self::assertStringNotContainsString('Ask for more information', $page, 'no decision buttons on an expired request');
        // the job runs later and closes it
        self::assertSame(1, $this->runJob());
        self::assertSame('expired', $this->statusOf($this->request));
    }

    #[Group('AC-44')]
    public function testARequestApprovedJustBeforeTheDeadlineIsNotExpiredByTheJobLater(): void
    {
        $this->clock->advance('47 hours 59 minutes');
        $this->touchSessions();
        $this->signInAsParent();
        $this->post('/family/approvals/' . $this->request . '/decision', ['decision' => 'approve']);
        $this->clock->advance('5 hours');

        self::assertSame(0, $this->runJob());
        self::assertSame('approved', $this->statusOf($this->request));
    }

    #[Group('AC-44')]
    public function testTheSchedulerRunsTheExpiryTaskEveryMinute(): void
    {
        $this->clock->advance('48 hours');
        $scheduler = $this->container->get(Scheduler::class);
        self::assertInstanceOf(Scheduler::class, $scheduler);

        $ran = $scheduler->tick();

        self::assertContains('approvals:expire', $ran);
        self::assertSame('expired', $this->statusOf($this->request));
        $tasks = (array) $this->container->get('scheduler.tasks');
        self::assertContains(\App\Approval\Infrastructure\ExpireApprovalsTask::class, $tasks);
        self::assertSame(60, (new \App\Approval\Infrastructure\ExpireApprovalsTask($this->container->get(ExpireApprovals::class)))->intervalSeconds());
    }
}
