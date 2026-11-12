<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use App\Family\Application\CancelUpcomingRegistrationsJob;
use App\Family\Application\RemoveChildFromTrainer;
use App\Family\Application\TrainerRoster;
use App\Integration\Application\EventRegistrations;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use PHPUnit\Framework\Attributes\Group;

final class RemoveChildTest extends FamilyHttpTestCase
{
    /** @return array{string, string, string, string} parent, child, organization A, organization B */
    private function scenario(): array
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $orgB] = $this->trainerWithOrganization('Baseline Club');
        $this->associate($child, $orgA);
        $this->associate($child, $orgB);

        return [$parent, $child, $orgA, $orgB];
    }

    #[Group('AC-35')]
    public function testRemovalFirstAsksForConfirmationAndChangesNothing(): void
    {
        [, $child, $orgA] = $this->scenario();

        $page = $this->get('/family/children/' . $child . '/trainers/' . $orgA . '/remove');

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Upcoming RSVPs', $this->body($page));
        self::assertStringContainsString('will be cancelled', $this->body($page));
        self::assertSame(2, $this->activeAssociations($child));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = :t", ['t' => RemoveChildFromTrainer::CANCEL_TOPIC]));
    }

    #[Group('AC-35')]
    public function testConfirmingEndsTheAssociationKeepsHistoryAndQueuesTheRsvpCancellation(): void
    {
        [$parent, $child, $orgA, $orgB] = $this->scenario();

        $response = $this->post('/family/children/' . $child . '/trainers/' . $orgA . '/remove');

        self::assertSame('/family/children?notice=removed', $this->location($response));
        $row = $this->row('SELECT ended_at, ended_by, end_reason FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o', ['p' => $child, 'o' => $orgA]);
        self::assertIsArray($row);
        self::assertNotNull($row['ended_at']);
        self::assertSame($parent, $row['ended_by']);
        self::assertSame('removed_by_parent', $row['end_reason']);
        self::assertSame(1, $this->activeAssociations($child), 'the other Trainer stays');
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o', ['p' => $child, 'o' => $orgA]), 'history row kept');
        $job = $this->row('SELECT payload FROM outbox WHERE topic = :t', ['t' => RemoveChildFromTrainer::CANCEL_TOPIC]);
        self::assertIsArray($job);
        self::assertSame(['profile_id' => $child, 'organization_id' => $orgA], json_decode((string) $job['payload'], true));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'child.removed_from_trainer'"));
        self::assertSame($orgB, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $child]));
    }

    #[Group('AC-35')]
    public function testTheTrainersRosterNoLongerShowsTheChild(): void
    {
        [, $child, $orgA, $orgB] = $this->scenario();
        $roster = $this->container->get(TrainerRoster::class);
        self::assertInstanceOf(TrainerRoster::class, $roster);
        self::assertCount(1, $roster->forOrganization(OrganizationId::fromString($orgA)));

        $this->post('/family/children/' . $child . '/trainers/' . $orgA . '/remove');

        self::assertSame([], $roster->forOrganization(OrganizationId::fromString($orgA)));
        self::assertCount(1, $roster->forOrganization(OrganizationId::fromString($orgB)));
    }

    #[Group('AC-35')]
    public function testTheQueuedJobCancelsOnlyThatTrainersUpcomingRegistrations(): void
    {
        [, $child, $orgA] = $this->scenario();
        $calls = [];
        $fake = new class ($calls) implements EventRegistrations {
            /** @param list<array{string, ?string}> $calls */
            public function __construct(public array &$calls) {}

            public function register(string $eventRef, string $playerProfileId): bool
            {
                return false;
            }

            public function cancelUpcomingFor(string $playerProfileId, ?string $organizationId = null): int
            {
                $this->calls[] = [$playerProfileId, $organizationId];

                return 1;
            }
        };
        \assert($this->container instanceof \DI\Container);
        $this->container->set(EventRegistrations::class, $fake);
        $this->post('/family/children/' . $child . '/trainers/' . $orgA . '/remove');
        $payload = json_decode((string) $this->scalar('SELECT payload FROM outbox WHERE topic = :t', ['t' => RemoveChildFromTrainer::CANCEL_TOPIC]), true);
        self::assertIsArray($payload);

        $job = $this->container->get(CancelUpcomingRegistrationsJob::class);
        self::assertInstanceOf(CancelUpcomingRegistrationsJob::class, $job);
        $job(new OutboxMessage('id', 'job', RemoveChildFromTrainer::CANCEL_TOPIC, $payload, 0));

        self::assertSame([[$child, $orgA]], $calls);
    }

    #[Group('AC-35')]
    public function testTheCancelTopicIsRegisteredWithTheOutboxWorker(): void
    {
        $handlers = $this->container->get(\App\Shared\Infrastructure\Outbox\JobOutboxHandler::class);
        self::assertInstanceOf(\App\Shared\Infrastructure\Outbox\JobOutboxHandler::class, $handlers);

        $handlers->handle(new OutboxMessage('id', 'job', RemoveChildFromTrainer::CANCEL_TOPIC, ['profile_id' => 'p', 'organization_id' => 'o'], 0));

        $this->addToAssertionCount(1); // no "No handler registered" exception: the Noop stub logs and returns 0
    }

    #[Group('AC-35')]
    public function testReAddingAfterRemovalIsTheParentsChoiceAndStartsANewRow(): void
    {
        [, $child, $orgA] = $this->scenario();
        [, $code] = $this->shareLink($orgA);
        $this->post('/family/children/' . $child . '/trainers/' . $orgA . '/remove');

        $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code]);

        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o', ['p' => $child, 'o' => $orgA]));
        self::assertSame(2, $this->activeAssociations($child));
    }

    #[Group('AC-35')]
    #[Group('AC-9')]
    public function testAnotherParentsChildOrAnUnassociatedTrainerIsNotFound(): void
    {
        $owner = $this->createUser('player', 'owner@example.com');
        $ownerChild = $this->childOf($owner);
        [, $org] = $this->trainerWithOrganization();
        $this->associate($ownerChild, $org);
        $me = $this->signInAs('player');
        $mine = $this->childOf($me, 'Mine');

        foreach (['/family/children/' . $ownerChild . '/trainers/' . $org . '/remove', '/family/children/' . $mine . '/trainers/' . $org . '/remove'] as $url) {
            self::assertSame(404, $this->get($url)->getStatusCode(), $url);
            self::assertSame(404, $this->post($url)->getStatusCode(), $url);
        }
        self::assertSame(1, $this->activeAssociations($ownerChild));
    }
}
