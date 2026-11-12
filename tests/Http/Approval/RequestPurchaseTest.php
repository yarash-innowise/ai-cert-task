<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use PHPUnit\Framework\Attributes\Group;

final class RequestPurchaseTest extends ApprovalHttpTestCase
{
    #[Group('AC-38')]
    public function testAChildsUsdPurchaseIsNotCompletedAndCreatesAPendingParentApprovalRequest(): void
    {
        $response = $this->purchase(self::EVENT, '25.00', ['event_title' => 'Spring Camp', 'note' => 'Please, all my friends go']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/requests?notice=requested', $this->location($response));
        $row = $this->requestRow();
        self::assertSame('pending', $row['status']);
        self::assertSame('purchase', $row['kind']);
        self::assertSame('usd', $row['payment_type']);
        self::assertSame(2500, (int) $row['amount_minor']);
        self::assertSame('USD', $row['currency']);
        self::assertSame(self::EVENT, $row['event_ref']);
        self::assertSame('Spring Camp', $row['event_title']);
        self::assertSame($this->kid, $row['child_user_id']);
        self::assertSame($this->mia, $row['child_profile_id']);
        self::assertSame($this->parent, $row['parent_user_id']);
        self::assertSame($this->org, $row['organization_id']);
        self::assertSame('Please, all my friends go', $row['child_note']);
        self::assertSame('2030-03-01 10:00:00', (new \DateTimeImmutable((string) $row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('2030-03-03 10:00:00', (new \DateTimeImmutable((string) $row['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertNull($row['decided_at']);
        // the purchase was not completed: nothing was handed to payments and nobody was registered
        self::assertSame([], $this->ports->handoffs);
        self::assertSame([], $this->ports->registered);
        self::assertSame([], $this->ports->spends);
    }

    #[Group('AC-38')]
    public function testTheRulesInForceAreSnapshottedWithTheRequest(): void
    {
        $this->pdo->prepare('UPDATE player_profiles SET token_spend_without_approval = true WHERE id = :p')->execute(['p' => $this->mia]);

        $this->purchase();

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $this->requestRow()['rule_snapshot'], true);
        self::assertTrue($snapshot['token_spend_without_approval']);
        self::assertSame('purchase', $snapshot['kind']);
    }

    #[Group('AC-38')]
    public function testTheChildSeesItsRequestAsPendingParentApproval(): void
    {
        $this->purchase(self::EVENT, '25.00', ['event_title' => 'Spring Camp']);

        $body = $this->body($this->get('/family/requests'));

        self::assertStringContainsString('Pending Parent Approval', $body);
        self::assertStringContainsString('Spring Camp', $body);
        self::assertStringContainsString('USD 25.00', $body);
    }

    #[Group('AC-38')]
    public function testAnInvalidOrUnknownEventCreatesNothing(): void
    {
        self::assertSame(422, $this->purchase('not-a-uuid')->getStatusCode());
        $unknown = $this->purchase('0190aaaa-0000-7000-8000-0000000000ff');
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('This event is not available', $this->body($unknown));
        self::assertSame(0, $this->requestCount());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_requested'"));
        self::assertSame([], $this->ports->handoffs);
    }

    #[Group('AC-38'), Group('AC-41')]
    public function testATamperedAmountCurrencyOrTitleIsIgnoredAndTheCatalogValuesAreStored(): void
    {
        $this->events->add($this->org, self::EVENT, 'Catalog Camp', 12345);

        $response = $this->purchase(self::EVENT, '0.01', ['event_title' => 'Free Money', 'currency' => 'EUR', 'amount_minor' => '1']);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->requestRow();
        self::assertSame(12345, (int) $row['amount_minor']);
        self::assertSame('USD', $row['currency']);
        self::assertSame('Catalog Camp', $row['event_title']);
        // a purchase request needs no amount from the child at all
        self::assertSame(302, $this->post('/family/requests/purchase', ['event_ref' => self::EVENT_2])->getStatusCode());
    }

    #[Group('AC-38'), Group('AC-41')]
    public function testAnEventOfAnotherOrganizationIsRefused(): void
    {
        [, $other] = $this->trainerWithOrganization('Beta Academy');
        $foreign = '0190aaaa-0000-7000-8000-0000000000f1';
        $this->events->add($other, $foreign, 'Beta Camp', 9900);

        foreach ([$this->purchase($foreign), $this->rsvp($foreign)] as $response) {
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-41')]
    public function testAnEventWithoutACatalogPriceCannotBePurchasedButCanBeRsvpedFor(): void
    {
        $this->events->add($this->org, self::EVENT, 'Free Clinic', null);

        $purchase = $this->purchase();
        self::assertSame(422, $purchase->getStatusCode());
        self::assertStringContainsString('cannot be bought online', $this->body($purchase));
        self::assertSame(0, $this->requestCount());

        self::assertSame(302, $this->rsvp()->getStatusCode());
        self::assertSame('Free Clinic', $this->requestRow()['event_title']);
    }

    #[Group('AC-38')]
    public function testTheProductionStubRefusesEveryEvent(): void
    {
        assert($this->container instanceof \DI\Container);
        $this->container->set(\App\Integration\Application\EventCatalog::class, new \App\Integration\Infrastructure\EmptyEventCatalog(new \Psr\Log\NullLogger()));

        self::assertSame(422, $this->purchase()->getStatusCode());
        self::assertSame(422, $this->rsvp()->getStatusCode());
        self::assertSame(422, $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => '5'])->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-38')]
    public function testAskingTwiceForTheSamePurchaseKeepsASingleOpenRequest(): void
    {
        $this->purchase();
        $this->purchase();

        self::assertSame(1, $this->requestCount());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_requested'"));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approval-request'"));
        // a different event is a different request
        $this->purchase(self::EVENT_2);
        self::assertSame(2, $this->requestCount());
    }

    #[Group('AC-38'), Group('AC-9')]
    public function testAParentWithChildrenCannotCreateRequests(): void
    {
        $this->signInAsParent();

        foreach (['/family/requests/purchase' => ['event_ref' => self::EVENT, 'amount' => '25'], '/family/requests/rsvp' => ['event_ref' => self::EVENT]] as $path => $body) {
            self::assertSame(403, $this->post($path, $body)->getStatusCode(), $path);
        }
        self::assertSame(403, $this->get('/family/requests')->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-38'), Group('AC-48')]
    public function testAParentAccountWithoutChildrenCannotCreateRequests(): void
    {
        $this->signInAs('player', 'lonely@example.com');

        self::assertSame(403, $this->purchase()->getStatusCode());
        self::assertSame(403, $this->rsvp()->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-9')]
    public function testOtherRolesAndGuestsCannotCreateRequests(): void
    {
        foreach (['trainer', 'coach'] as $role) {
            $this->signInAs($role);
            self::assertSame(403, $this->purchase()->getStatusCode(), $role);
        }
        $this->cookies = [];
        self::assertSame(302, $this->get('/family/requests')->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-38')]
    public function testARequestIsRefusedWhenTheParentAccountIsNotActive(): void
    {
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :p")->execute(['p' => $this->parent]);

        $response = $this->purchase();

        // the child's own session ends with the guardian (cascade) or the request is refused; either way nothing is created
        self::assertContains($response->getStatusCode(), [302, 403, 409]);
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-38')]
    public function testRequestsAreWrittenWithAnAuditEntryWithoutPersonalData(): void
    {
        $this->purchase();

        $audit = $this->row("SELECT actor_user_id, payload FROM audit_log WHERE action = 'approval.requested'");
        self::assertIsArray($audit);
        self::assertSame($this->kid, $audit['actor_user_id']);
        self::assertStringNotContainsString('Mia', (string) $audit['payload']);
        self::assertStringNotContainsString('example.com', (string) $audit['payload']);
    }
}
