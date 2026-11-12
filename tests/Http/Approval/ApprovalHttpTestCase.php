<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Integration\Application\EventRegistrations;
use App\Integration\Application\PurchaseHandoff;
use App\Integration\Application\RsvpCancellations;
use App\Integration\Application\TokenSpend;
use Tests\Http\Family\FamilyHttpTestCase;

/**
 * Fixtures for the approval workflow: a Trainer, a parent, a child with its own login in the active
 * context, and recording stand-ins for the Epic-02 / Epic-05 ports. The frozen clock is 2030-03-01 10:00 UTC.
 */
abstract class ApprovalHttpTestCase extends FamilyHttpTestCase
{
    protected const EVENT = '0190aaaa-0000-7000-8000-0000000000e1';
    protected const EVENT_2 = '0190aaaa-0000-7000-8000-0000000000e2';
    protected const PARENT_EMAIL = 'parent@example.com';
    protected const KID_EMAIL = 'kid@example.com';

    protected string $trainer;
    protected string $org;
    protected string $parent;
    protected string $mia;
    protected string $kid;
    protected RecordingPorts $ports;
    protected \Tests\Support\FakeEventCatalog $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ports = new RecordingPorts();
        assert($this->container instanceof \DI\Container);
        $this->container->set(EventRegistrations::class, $this->ports->registrations());
        $this->container->set(PurchaseHandoff::class, $this->ports->handoff());
        $this->container->set(RsvpCancellations::class, $this->ports->cancellations());
        $this->container->set(TokenSpend::class, $this->ports->tokens());
        [$this->trainer, $this->org] = $this->trainerWithOrganization('Ace Tennis Academy');
        // The events capability (Epic-02) is a test double: title and price are the catalog's (F-11)
        $this->events = new \Tests\Support\FakeEventCatalog();
        $this->events->add($this->org, self::EVENT);
        $this->events->add($this->org, self::EVENT_2);
        $this->container->set(\App\Integration\Application\EventCatalog::class, $this->events);
        $this->parent = $this->createUser('player', self::PARENT_EMAIL, name: 'Pat Parent');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Smith', '2018-05-01');
        $this->associate($this->mia, $this->org);
        $this->kid = $this->signInAsChildLogin($this->parent, $this->mia, self::KID_EMAIL);
    }

    protected function signInAsParent(): void
    {
        $this->cookies = [];
        $this->login(self::PARENT_EMAIL);
    }

    protected function signInAsKid(): void
    {
        $this->cookies = [];
        $this->login(self::KID_EMAIL);
    }

    /** @param array<string, string> $extra */
    protected function purchase(string $event = self::EVENT, string $amount = '25.00', array $extra = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/requests/purchase', ['event_ref' => $event, 'amount' => $amount, ...$extra]);
    }

    /** @param array<string, string> $extra */
    protected function rsvp(string $event = self::EVENT, array $extra = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/requests/rsvp', ['event_ref' => $event, ...$extra]);
    }

    /** @return array<string, mixed> */
    protected function requestRow(?string $event = null): array
    {
        $row = $this->row('SELECT * FROM approval_requests WHERE event_ref = :e ORDER BY created_at DESC LIMIT 1', ['e' => $event ?? self::EVENT]);
        self::assertIsArray($row, 'an approval request exists');

        return $row;
    }

    protected function requestCount(): int
    {
        return (int) $this->scalar('SELECT count(*) FROM approval_requests');
    }

    /** Opens a request straight in the database (as the child's earlier action would have). */
    protected function openRequest(
        string $kind = 'purchase',
        string $status = 'pending',
        string $event = self::EVENT,
        string $created = '2030-03-01 10:00:00+00',
        int $amount = 2500,
        string $payment = 'usd',
        string $snapshot = '{"token_spend_without_approval": false}',
    ): string {
        return (string) $this->scalar(
            "INSERT INTO approval_requests (organization_id, child_profile_id, child_user_id, parent_user_id, kind, event_ref, event_title, amount_minor, payment_type, status, rule_snapshot, created_at, updated_at, expires_at)
             VALUES (:o, :p, :c, :par, :k, :e, 'Spring Camp', :a, :pt, :s, CAST(:snap AS jsonb), CAST(:t AS timestamptz), CAST(:t AS timestamptz), CAST(:t AS timestamptz) + interval '48 hours') RETURNING id",
            ['o' => $this->org, 'p' => $this->mia, 'c' => $this->kid, 'par' => $this->parent, 'k' => $kind, 'e' => $event, 'a' => $amount, 'pt' => $payment, 's' => $status, 'snap' => $snapshot, 't' => $created],
        );
    }

    /** Keeps the current sessions alive after the frozen clock was moved. */
    protected function touchSessions(): void
    {
        $this->pdo->prepare('UPDATE sessions SET last_seen_at = :t, created_at = :t')->execute(['t' => $this->clock->now()->format('Y-m-d H:i:s.uP')]);
    }
}
