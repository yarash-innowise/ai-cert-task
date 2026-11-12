<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Integration\Application\SpendResult;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class TokenSpendApprovalTest extends ApprovalHttpTestCase
{
    private function allowWithoutApproval(bool $on = true): void
    {
        $this->pdo->prepare('UPDATE player_profiles SET token_spend_without_approval = :v WHERE id = :p')->execute(['v' => $on ? 't' : 'f', 'p' => $this->mia]);
    }

    /** @param array<string, string> $extra */
    private function spend(string $tokens = '5', array $extra = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => $tokens, 'event_title' => 'Spring Camp', ...$extra]);
    }

    #[Group('AC-43')]
    public function testWithTheSettingOffATokenSpendFollowsTheApprovalWorkflow(): void
    {
        $response = $this->spend('5');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/requests?notice=requested', $this->location($response));
        $row = $this->requestRow();
        self::assertSame('token_spend', $row['kind']);
        self::assertSame('tokens', $row['payment_type']);
        self::assertSame(5, (int) $row['amount_minor']);
        self::assertSame('pending', $row['status']);
        self::assertSame($this->parent, $row['parent_user_id']);
        self::assertSame([], $this->ports->spends, 'no tokens are spent before the parent approves');
        self::assertSame([], $this->ports->registered);
        $body = $this->body($this->get('/family/requests'));
        self::assertStringContainsString('Pending Parent Approval', $body);
        self::assertStringContainsString('5 tokens', $body);
        // the same notification as for a USD request
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_requested' AND user_id = :p", ['p' => $this->parent]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approval-request'"));
    }

    #[Group('AC-43'), Group('AC-41')]
    public function testAnUnknownEventIsRefusedBeforeAnyTokensAreSpent(): void
    {
        $this->allowWithoutApproval();

        $response = $this->post('/family/requests/token-spend', ['event_ref' => '0190aaaa-0000-7000-8000-0000000000ff', 'tokens' => '5']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->ports->spends);
        self::assertSame([], $this->ports->registered);
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-43')]
    public function testAnApprovedTokenRequestIsQueuedForTheHandoffLikeAnyOther(): void
    {
        $this->spend('5');
        $this->signInAsParent();

        $this->post('/family/approvals/' . $this->requestRow()['id'] . '/decision', ['decision' => 'approve']);

        self::assertSame('approved', $this->requestRow()['status']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approvals.handoff'"));
    }

    #[Group('AC-43')]
    public function testWithTheSettingOnTheSpendProceedsWithoutApproval(): void
    {
        $this->allowWithoutApproval();

        $response = $this->spend('5');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/requests?notice=spent', $this->location($response));
        self::assertSame(0, $this->requestCount(), 'no approval request is created');
        self::assertSame([[$this->parent, 5, 'child-' . $this->mia . '-event-' . self::EVENT]], $this->ports->spends);
        self::assertSame([[self::EVENT, $this->mia]], $this->ports->registered, 'registered because the spend succeeded');
        $audit = $this->row("SELECT payload FROM audit_log WHERE action = 'token_spend.completed'");
        self::assertIsArray($audit);
        self::assertSame(false, json_decode((string) $audit['payload'], true)['approval_required']);
    }

    #[Group('AC-43'), Group('AC-86')]
    public function testTheParentGetsAnInformationalNoticeWithNoApproveAction(): void
    {
        $this->allowWithoutApproval();

        $this->spend('5');

        $note = $this->row("SELECT * FROM notifications WHERE kind = 'token_spend_notice'");
        self::assertIsArray($note);
        self::assertSame($this->parent, $note['user_id']);
        self::assertStringContainsString('Mia Smith spent 5 tokens', (string) $note['title']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $note['payload'], true);
        self::assertTrue($payload['informational']);
        self::assertArrayNotHasKey('request_id', $payload, 'there is no request to approve');
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approval-request'"));
        $mail = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'token-spend-notice'");
        self::assertIsArray($mail);
        /** @var array{to: string, context: array<string, mixed>} $email */
        $email = json_decode((string) $mail['payload'], true);
        self::assertSame(self::PARENT_EMAIL, $email['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $rendered = $renderer->render('token-spend-notice', $email['to'], $email['context'], 'm1');
        self::assertSame('Mia Smith spent 5 tokens', $rendered->subject);
        self::assertStringContainsString('for your information only', $rendered->html);
        self::assertStringNotContainsString('/family/approvals', $rendered->html . $rendered->text);
        self::assertStringNotContainsStringIgnoringCase('approve</a>', $rendered->html);
    }

    #[Group('AC-43')]
    public function testASpendTheCapabilityCannotMakeCreatesNeitherARequestNorANoticeNorARegistration(): void
    {
        $this->allowWithoutApproval();
        foreach ([SpendResult::Unavailable, SpendResult::InsufficientBalance] as $result) {
            $this->ports->spendResult = $result;

            $response = $this->spend('5');

            self::assertSame(422, $response->getStatusCode(), $result->value);
            self::assertStringContainsString('could not be spent', $this->body($response));
        }
        self::assertSame(0, $this->requestCount());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'token_spend_notice'"));
        self::assertSame([], $this->ports->registered);
    }

    #[Group('AC-43')]
    public function testTheNumberOfTokensIsValidated(): void
    {
        foreach (['', '0', '-1', 'abc', '1.5', '1000000'] as $tokens) {
            self::assertSame(422, $this->spend($tokens)->getStatusCode(), 'tokens ' . $tokens);
        }
        self::assertSame(0, $this->requestCount());
        self::assertSame([], $this->ports->spends);
    }

    #[Group('AC-43'), Group('AC-9')]
    public function testOnlyAChildLoginCanSpendThroughThisRoute(): void
    {
        $this->allowWithoutApproval();
        $this->signInAsParent();
        $asParent = $this->spend('5');
        $this->signInAs('trainer');
        $asTrainer = $this->spend('5');

        self::assertSame(403, $asParent->getStatusCode());
        self::assertSame(403, $asTrainer->getStatusCode());

        self::assertSame([], $this->ports->spends);
        self::assertSame(0, $this->requestCount());
    }
}
