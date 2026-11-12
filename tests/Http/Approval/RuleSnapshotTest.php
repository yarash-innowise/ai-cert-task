<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use PHPUnit\Framework\Attributes\Group;

/** Edge case: the token setting is changed while a request is pending. */
final class RuleSnapshotTest extends ApprovalHttpTestCase
{
    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $this->requestRow()['rule_snapshot'], true);

        return $snapshot;
    }

    private function parentSets(string $value): void
    {
        $this->signInAsParent();
        $this->post('/family/children/' . $this->mia . '/settings', ['token_spend_without_approval' => $value]);
    }

    #[Group('AC-43')]
    public function testARequestRecordsTheSettingInForceWhenTheChildAsked(): void
    {
        $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => '3']);

        self::assertFalse($this->snapshot()['token_spend_without_approval']);
        self::assertSame('token_spend', $this->snapshot()['kind']);
        self::assertSame('tokens', $this->snapshot()['payment_type']);
    }

    #[Group('AC-43')]
    public function testTurningTheSettingOnWhileARequestIsPendingLeavesThatRequestGovernedByItsSnapshot(): void
    {
        $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => '3']);
        $id = (string) $this->requestRow()['id'];

        $this->parentSets('1');

        $row = $this->requestRow();
        self::assertSame('pending', $row['status'], 'the pending request is not decided or spent by the new setting');
        self::assertFalse($this->snapshot()['token_spend_without_approval']);
        self::assertSame([], $this->ports->spends);
        // it still needs the parent's decision, and a decision works as before
        self::assertSame(302, $this->post('/family/approvals/' . $id . '/decision', ['decision' => 'approve'])->getStatusCode());
        self::assertSame('approved', $this->requestRow()['status']);
        self::assertFalse($this->snapshot()['token_spend_without_approval']);
    }

    #[Group('AC-43')]
    public function testTurningTheSettingOffAgainDoesNotChangeARequestMadeWhileItWasOn(): void
    {
        $this->parentSets('1');
        $this->signInAsKid();
        $this->post('/family/requests/purchase', ['event_ref' => self::EVENT, 'amount' => '25']);
        self::assertTrue($this->snapshot()['token_spend_without_approval']);

        $this->parentSets('0');

        self::assertTrue($this->snapshot()['token_spend_without_approval'], 'the rules at the time of asking stay recorded');
        self::assertSame('pending', $this->requestRow()['status']);
    }

    #[Group('AC-43')]
    public function testTheNewSettingAppliesToLaterRequests(): void
    {
        $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => '3']);
        $this->parentSets('1');
        $this->signInAsKid();

        $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT_2, 'tokens' => '4']);

        self::assertSame(1, $this->requestCount(), 'the second spend went straight through');
        self::assertCount(1, $this->ports->spends);
        self::assertSame(4, $this->ports->spends[0][1]);
    }
}
