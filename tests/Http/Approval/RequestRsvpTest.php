<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use PHPUnit\Framework\Attributes\Group;

final class RequestRsvpTest extends ApprovalHttpTestCase
{
    #[Group('AC-48')]
    public function testAChildsRsvpCreatesARequestAndNeverRegistersTheChildDirectly(): void
    {
        $response = $this->rsvp(self::EVENT, ['event_title' => 'Free Saturday Clinic']);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->requestRow();
        self::assertSame('rsvp', $row['kind']);
        self::assertSame('none', $row['payment_type']);
        self::assertSame(0, (int) $row['amount_minor']);
        self::assertSame('pending', $row['status']);
        self::assertSame($this->parent, $row['parent_user_id']);
        self::assertSame([], $this->ports->registered, 'the child is not registered before the parent approves');
        self::assertStringContainsString('Pending Parent Approval', $this->body($this->get('/family/requests')));
    }

    #[Group('AC-48')]
    public function testAChildsRsvpCancellationCreatesARequestAndCancelsNothing(): void
    {
        $response = $this->post('/family/requests/rsvp-cancel', ['event_ref' => self::EVENT]);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->requestRow();
        self::assertSame('rsvp_cancel', $row['kind']);
        self::assertSame('pending', $row['status']);
        self::assertSame([], $this->ports->cancelled);
        self::assertStringContainsString('Rsvp cancellation', $this->body($this->get('/family/requests')));
    }

    #[Group('AC-48')]
    public function testAnRsvpAndItsCancellationAreSeparateRequestsForTheSameEvent(): void
    {
        $this->rsvp();
        $this->post('/family/requests/rsvp-cancel', ['event_ref' => self::EVENT]);

        self::assertSame(2, $this->requestCount());
    }

    #[Group('AC-48')]
    public function testParentsCannotRsvpOrCancelForTheChildThroughTheChildRoutes(): void
    {
        $this->signInAsParent();

        self::assertSame(403, $this->rsvp()->getStatusCode());
        self::assertSame(403, $this->post('/family/requests/rsvp-cancel', ['event_ref' => self::EVENT])->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }

    #[Group('AC-48')]
    public function testARequestNeedsAValidEventAndIsRecordedInTheActiveTrainersOrganization(): void
    {
        self::assertSame(422, $this->rsvp('nope')->getStatusCode());
        self::assertSame(0, $this->requestCount());

        $this->rsvp();
        self::assertSame($this->org, $this->requestRow()['organization_id']);
    }

    #[Group('AC-48')]
    public function testAChildRemovedFromTheTrainerCannotAskAnyMore(): void
    {
        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = :t WHERE player_profile_id = :p')->execute(['t' => '2030-02-01 00:00:00+00', 'p' => $this->mia]);

        $response = $this->rsvp();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->requestCount());
    }
}
