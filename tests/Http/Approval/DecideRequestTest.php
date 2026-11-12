<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class DecideRequestTest extends ApprovalHttpTestCase
{
    private string $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->openRequest();
        $this->signInAsParent();
    }

    /** @param array<string, string> $extra */
    private function decide(string $decision, array $extra = [], ?string $id = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/approvals/' . ($id ?? $this->request) . '/decision', ['decision' => $decision, ...$extra]);
    }

    private function statusOfRequest(): string
    {
        return (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $this->request]);
    }

    #[Group('AC-40')]
    public function testTheParentApprovesWithANoteAndTheHandoffIsQueued(): void
    {
        $response = $this->decide('approve', ['note' => 'Have fun!']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/approvals/' . $this->request . '?notice=approve', $this->location($response));
        $row = $this->requestRow();
        self::assertSame('approved', $row['status']);
        self::assertSame('Have fun!', $row['parent_note']);
        self::assertSame($this->parent, $row['decided_by']);
        self::assertNotNull($row['decided_at']);
        self::assertSame(2, (int) $row['version']);
        $job = $this->row("SELECT payload FROM outbox WHERE kind = 'job' AND topic = 'approvals.handoff'");
        self::assertIsArray($job);
        self::assertSame(['request_id' => $this->request], json_decode((string) $job['payload'], true));
        // nothing is completed here: payment and registration happen in the hand-off job only
        self::assertSame([], $this->ports->handoffs);
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-40')]
    public function testTheParentDeniesWithANoteAndNothingIsHandedOver(): void
    {
        $this->decide('deny', ['note' => 'Not this month']);

        $row = $this->requestRow();
        self::assertSame('denied', $row['status']);
        self::assertSame('Not this month', $row['parent_note']);
        self::assertNotNull($row['decided_at']);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-40')]
    public function testTheParentCanAskForMoreInformationAndStillDecideAfterwards(): void
    {
        $this->decide('info', ['note' => 'Who else is going?']);

        $row = $this->requestRow();
        self::assertSame('info_requested', $row['status']);
        self::assertSame('Who else is going?', $row['parent_note']);
        self::assertNull($row['decided_at'], 'asking is not a decision');
        self::assertNull($row['decided_by']);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));

        $this->decide('approve');
        self::assertSame('approved', $this->statusOfRequest());
        self::assertNull($this->requestRow()['parent_note']);
    }

    #[Group('AC-40')]
    public function testTheNoteIsOptionalAndLimited(): void
    {
        self::assertSame(302, $this->decide('deny')->getStatusCode());
        self::assertNull($this->requestRow()['parent_note']);

        $second = $this->openRequest('rsvp', 'pending', self::EVENT_2, payment: 'none', amount: 0);
        $tooLong = $this->decide('approve', ['note' => str_repeat('x', 501)], $second);
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertSame('pending', (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $second]));
        self::assertSame(422, $this->decide('maybe', [], $second)->getStatusCode());
        self::assertSame('pending', (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $second]));
    }

    #[Group('AC-40')]
    public function testTheChildSeesTheStatusChange(): void
    {
        $this->signInAsKid();
        self::assertStringContainsString('Pending Parent Approval', $this->body($this->get('/family/requests')));

        $this->signInAsParent();
        $this->decide('approve', ['note' => 'Have fun!']);
        $this->signInAsKid();
        $approved = $this->body($this->get('/family/requests'));
        self::assertStringContainsString('Confirmed', $approved);
        self::assertStringContainsString('Have fun!', $approved);
        self::assertStringNotContainsString('Pending Parent Approval', $approved);
    }

    #[Group('AC-40')]
    public function testTheChildSeesDeniedAndInfoRequested(): void
    {
        $other = $this->openRequest('rsvp', 'pending', self::EVENT_2, payment: 'none', amount: 0);
        $this->decide('deny', ['note' => 'No']);
        $this->decide('info', ['note' => 'Tell me more'], $other);

        $this->signInAsKid();
        $body = $this->body($this->get('/family/requests'));

        self::assertStringContainsString('Denied', $body);
        self::assertStringContainsString('More information requested', $body);
        self::assertStringContainsString('Tell me more', $body);
    }

    #[Group('AC-40'), Group('AC-86')]
    public function testTheChildIsNotifiedInTheAppAndByEmailOfTheOutcome(): void
    {
        $this->decide('approve', ['note' => 'Have fun!']);

        $note = $this->row("SELECT * FROM notifications WHERE kind = 'approval_approved'");
        self::assertIsArray($note);
        self::assertSame($this->kid, $note['user_id']);
        self::assertStringContainsString('was approved', (string) $note['title']);
        $mail = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'approval-outcome'");
        self::assertIsArray($mail);
        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $mail['payload'], true);
        self::assertSame(self::KID_EMAIL, $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $rendered = $renderer->render('approval-outcome', $payload['to'], $payload['context'], 'm1');
        self::assertSame('Your purchase was approved', $rendered->subject);
        self::assertStringContainsString('Have fun!', $rendered->html);
        self::assertStringContainsString('Spring Camp', $rendered->text);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM notifications WHERE user_id = :p', ['p' => $this->parent]), 'the parent is not told about their own answer');
    }

    #[Group('AC-45')]
    public function testOnlyTheChildsOwnParentCanDecide(): void
    {
        $other = $this->createUser('player', 'other-parent@example.com', name: 'Olga Other');
        $this->childOf($other, 'Zed', 'Zebra');
        $this->cookies = [];
        $this->login('other-parent@example.com');

        $response = $this->decide('approve');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('pending', $this->statusOfRequest());
        self::assertSame(404, $this->get('/family/approvals/' . $this->request)->getStatusCode());
        self::assertStringNotContainsString('Mia', $this->body($this->get('/family/approvals')));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
    }

    #[Group('AC-45')]
    public function testAChildLoginAndOtherRolesCannotDecide(): void
    {
        $this->signInAsKid();
        self::assertSame(403, $this->decide('approve')->getStatusCode(), 'the child cannot approve their own request');
        self::assertSame(403, $this->get('/family/approvals/' . $this->request)->getStatusCode());
        foreach (['trainer', 'coach'] as $role) {
            $this->signInAs($role);
            self::assertSame(403, $this->decide('approve')->getStatusCode(), $role);
        }
        $this->cookies = [];
        self::assertSame(302, $this->get('/family/approvals/' . $this->request)->getStatusCode());
        self::assertSame('pending', $this->statusOfRequest());
    }

    #[Group('AC-45')]
    public function testUnknownAndMalformedRequestsAreNotFound(): void
    {
        self::assertSame(404, $this->decide('approve', [], '00000000-0000-7000-8000-000000000000')->getStatusCode());
        self::assertSame(404, $this->decide('approve', [], 'nonsense')->getStatusCode());
    }

    #[Group('AC-45')]
    public function testASecondDecisionIsRefusedWithTheCurrentStatus(): void
    {
        $this->decide('approve');

        $again = $this->decide('approve');
        $other = $this->decide('deny');

        self::assertSame(409, $again->getStatusCode());
        self::assertStringContainsString('already answered', $this->body($again));
        self::assertStringContainsString('Approved (payment pending)', $this->body($again));
        self::assertSame(409, $other->getStatusCode());
        self::assertSame('approved', $this->statusOfRequest());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"), 'one hand-off job only');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind LIKE 'approval_%' AND user_id = :k", ['k' => $this->kid]));
    }

    #[Group('AC-45')]
    public function testADeniedRequestCannotBeApprovedLater(): void
    {
        $this->decide('deny');

        $response = $this->decide('approve');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('denied', $this->statusOfRequest());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
    }

    #[Group('AC-45')]
    public function testADecisionAfter48HoursIsRefusedAsExpiredAndTheChildIsNotRegistered(): void
    {
        $this->clock->advance('48 hours');
        $this->touchSessions();

        $response = $this->decide('approve');

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('expired', $this->body($response));
        self::assertSame('pending', $this->statusOfRequest(), 'the expiry job has not run, yet the decision is refused');
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
        self::assertSame([], $this->ports->registered);
        self::assertSame([], $this->ports->handoffs);
    }

    #[Group('AC-45')]
    public function testADecisionJustBefore48HoursIsStillAccepted(): void
    {
        $this->clock->advance('47 hours 59 minutes');
        $this->touchSessions();

        self::assertSame(302, $this->decide('approve')->getStatusCode());
        self::assertSame('approved', $this->statusOfRequest());
    }

    #[Group('AC-40')]
    public function testTheInboxAndRequestPageShowOnlyWhatCanStillBeDecided(): void
    {
        $inbox = $this->body($this->get('/family/approvals'));
        self::assertStringContainsString('Waiting for your decision', $inbox);
        self::assertStringContainsString('Mia Smith', $inbox);
        $page = $this->body($this->get('/family/approvals/' . $this->request));
        self::assertStringContainsString('Approve', $page);
        self::assertStringContainsString('Ask for more information', $page);

        $this->decide('deny');
        $decided = $this->body($this->get('/family/approvals/' . $this->request));
        self::assertStringNotContainsString('Ask for more information', $decided);
        self::assertStringContainsString('Denied', $decided);
    }

    #[Group('AC-45')]
    public function testTheDecisionIsAuditedWithoutPersonalData(): void
    {
        $this->decide('approve', ['note' => 'private words']);

        $audit = $this->row("SELECT actor_user_id, payload FROM audit_log WHERE action = 'approval.approved'");
        self::assertIsArray($audit);
        self::assertSame($this->parent, $audit['actor_user_id']);
        self::assertStringNotContainsString('private words', (string) $audit['payload']);
    }
}
