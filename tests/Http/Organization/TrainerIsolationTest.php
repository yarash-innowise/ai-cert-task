<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/** AC-10 on the Trainer's own pages: another Trainer's records answer 404 and leak nothing. */
#[Group('AC-10')]
final class TrainerIsolationTest extends OrganizationHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $parentA;
    private string $parentB;
    private string $childA;
    private string $childB;
    private string $coachA;
    private string $coachB;

    protected function setUp(): void
    {
        parent::setUp();
        $trainerA = $this->createUser('trainer', 'a@example.com', name: 'Tina Alpha');
        $trainerB = $this->createUser('trainer', 'b@example.com', name: 'Ben Beta');
        $this->orgA = (string) $this->scalar("INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, 'Alpha Academy') RETURNING id", ['o' => $trainerA]);
        $this->orgB = (string) $this->scalar("INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, 'Beta Club') RETURNING id", ['o' => $trainerB]);
        $this->parentA = $this->createUser('player', 'pa@example.com', name: 'Paula Alpha');
        $this->parentB = $this->createUser('player', 'pb@example.com', name: 'Peter Beta');
        $this->childA = $this->childOf($this->parentA, 'Anna', 'Alpha');
        $this->childB = $this->childOf($this->parentB, 'Bert', 'Beta');
        $this->associate($this->childA, $this->orgA);
        $this->associate($this->childB, $this->orgB);
        $this->coachA = $this->coachUser('ca@example.com', 'Carla Alpha');
        $this->coachB = $this->coachUser('cb@example.com', 'Carl Beta');
        $membership = $this->pdo->prepare('INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)');
        $membership->execute(['o' => $this->orgA, 'c' => $this->coachA]);
        $membership->execute(['o' => $this->orgB, 'c' => $this->coachB]);
        $this->login('a@example.com');
    }

    private function normalized(ResponseInterface $response): string
    {
        return (string) preg_replace('/(nonce|value)="[^"]+"/', '$1=""', $this->body($response));
    }

    public function testTrainerARosterListsOnlyOrganizationAPeople(): void
    {
        $players = $this->body($this->get('/trainer/players'));
        $coaches = $this->body($this->get('/trainer/coaches'));

        self::assertStringContainsString('Anna Alpha', $players);
        self::assertStringNotContainsString('Bert Beta', $players);
        self::assertStringNotContainsString('Peter Beta', $players);
        self::assertStringContainsString('Carla Alpha', $coaches);
        self::assertStringNotContainsString('Carl Beta', $coaches);
        self::assertStringNotContainsString('cb@example.com', $coaches);
    }

    public function testTrainerAOwnPlayerAndCoachPagesWork(): void
    {
        self::assertSame(200, $this->get('/trainer/players/' . $this->childA)->getStatusCode());
        self::assertSame(200, $this->get('/trainer/coaches/' . $this->coachA)->getStatusCode());
    }

    public function testRequestingAnotherTrainersPlayerByAddressGivesNotFoundAndNoData(): void
    {
        $response = $this->get('/trainer/players/' . $this->childB);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Bert', $this->body($response));
        self::assertStringNotContainsString('Beta', $this->body($response));
    }

    public function testRequestingAnotherTrainersCoachByAddressGivesNotFoundAndNoData(): void
    {
        $response = $this->get('/trainer/coaches/' . $this->coachB);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Carl', $this->body($response));
        self::assertStringNotContainsString('cb@example.com', $this->body($response));
    }

    public function testRequestingAnotherOrganizationsUserIdsOnTheRosterRoutesGivesNotFound(): void
    {
        foreach ([$this->parentB, $this->parentA, $this->coachB] as $userId) {
            self::assertSame(404, $this->get('/trainer/players/' . $userId)->getStatusCode(), 'player route with a user id');
        }
        self::assertSame(404, $this->get('/trainer/coaches/' . $this->coachB)->getStatusCode());
        self::assertSame(404, $this->get('/trainer/coaches/' . $this->parentB)->getStatusCode());
    }

    public function testAForeignRecordLooksExactlyLikeAMissingOne(): void
    {
        $foreign = $this->get('/trainer/players/' . $this->childB);
        $unknown = $this->get('/trainer/players/00000000-0000-7000-8000-000000000000');
        $malformed = $this->get('/trainer/players/not-a-uuid');
        $foreignCoach = $this->get('/trainer/coaches/' . $this->coachB);
        $unknownCoach = $this->get('/trainer/coaches/00000000-0000-7000-8000-000000000000');

        self::assertSame($this->normalized($foreign), $this->normalized($unknown));
        self::assertSame($this->normalized($foreign), $this->normalized($malformed));
        self::assertSame($this->normalized($foreignCoach), $this->normalized($unknownCoach));
    }

    public function testAnotherTrainersInvitationsAreNotListed(): void
    {
        $this->signOut();
        $this->login('b@example.com');
        $this->invite('secret-candidate@example.com');
        $this->signOut();
        $this->login('a@example.com');

        self::assertStringNotContainsString('secret-candidate@example.com', $this->body($this->get('/trainer/coaches')));
    }

    public function testOtherRolesCannotOpenTheRosterPages(): void
    {
        foreach (['coach', 'player'] as $role) {
            $this->signInAs($role);
            foreach (['/trainer/players', '/trainer/players/' . $this->childA, '/trainer/coaches/' . $this->coachA] as $path) {
                self::assertSame(403, $this->get($path)->getStatusCode(), $role . ' ' . $path);
            }
        }
    }
}
