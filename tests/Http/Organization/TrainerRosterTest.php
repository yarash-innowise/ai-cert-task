<?php

declare(strict_types=1);

namespace Tests\Http\Organization;

use PHPUnit\Framework\Attributes\Group;

final class TrainerRosterTest extends OrganizationHttpTestCase
{
    private string $orgA;
    private string $parent;
    private string $mia;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerSignedIn('Ace Tennis Academy');
        $this->signOut();
        $this->parent = $this->createUser('player', 'parent@example.com', name: 'Pat Parent');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Smith', '2022-05-01');
        $this->associate($this->mia, $this->orgA);
        $this->login($this->trainerEmail());
    }

    private function trainerEmail(): string
    {
        return (string) $this->scalar("SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o", ['o' => $this->orgA]);
    }

    #[Group('AC-10'), Group('AC-28')]
    public function testTheTrainerSeesTheirPlayersWithAgeAndAccountHolder(): void
    {
        $adult = $this->createUser('player', 'adult@example.com', name: 'Alex Adult');
        $self = $this->selfOf($adult, 'Alex', 'Adult', '1990-01-15');
        $this->associate($self, $this->orgA);

        $response = $this->get('/trainer/players');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertMatchesRegularExpression('/Mia Smith.*?Child.*?<td>7<\/td>.*?Pat Parent/s', $body);
        self::assertMatchesRegularExpression('/Alex Adult.*?Adult.*?<td>40<\/td>.*?Alex Adult/s', $body);
        self::assertStringContainsString('href="/trainer/players/' . $this->mia . '"', $body);
    }

    #[Group('AC-10')]
    public function testOnlyPeopleOfTheTrainersOwnOrganizationAreListed(): void
    {
        [, $orgB] = $this->otherOrganization('Beta Padel Club');
        $other = $this->createUser('player', 'other@example.com', name: 'Olga Other');
        $this->associate($this->childOf($other, 'Zed', 'Zebra'), $orgB);
        $both = $this->childOf($this->parent, 'Both', 'Ways', '2020-01-01');
        $this->associate($both, $this->orgA);
        $this->associate($both, $orgB);

        $body = $this->body($this->get('/trainer/players'));

        self::assertStringContainsString('Mia Smith', $body);
        self::assertStringContainsString('Both Ways', $body);
        self::assertStringNotContainsString('Zed Zebra', $body);
        self::assertStringNotContainsString('Olga Other', $body);
        self::assertStringNotContainsString('Beta Padel Club', $body);
    }

    #[Group('AC-10')]
    public function testARemovedChildIsAbsentFromTheRosterButItsHistoryRemains(): void
    {
        $this->pdo->prepare("UPDATE player_trainer_associations SET ended_at = :t, ended_by = :p, end_reason = 'removed_by_parent' WHERE player_profile_id = :c")
            ->execute(['t' => '2030-02-20 10:00:00+00', 'p' => $this->parent, 'c' => $this->mia]);

        $list = $this->body($this->get('/trainer/players'));
        $detail = $this->get('/trainer/players/' . $this->mia);

        self::assertStringNotContainsString('Mia Smith', $list);
        self::assertSame(404, $detail->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :c', ['c' => $this->mia]));
    }

    #[Group('AC-74')]
    public function testAnInactiveUsersPlayersStayOnTheRosterMarkedInactive(): void
    {
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :p")->execute(['p' => $this->parent]);
        $active = $this->createUser('player', 'active@example.com', name: 'Ann Active');
        $this->associate($this->childOf($active, 'Zoe', 'Zing'), $this->orgA);

        $list = $this->body($this->get('/trainer/players'));
        $detail = $this->body($this->get('/trainer/players/' . $this->mia));

        self::assertMatchesRegularExpression('/Mia Smith<\/a> <span class="badge badge--inactive">Inactive<\/span>/', $list);
        self::assertDoesNotMatchRegularExpression('/Zoe Zing<\/a> <span class="badge badge--inactive">/', $list);
        self::assertStringContainsString('Inactive', $detail);
        self::assertStringContainsString('Their history stays on your roster.', $detail);
        // nothing was deleted: the association (history) is intact
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :c AND ended_at IS NULL', ['c' => $this->mia]));
    }

    #[Group('AC-74')]
    public function testAChildWhoseOwnLoginIsDeactivatedIsMarkedInactive(): void
    {
        $kid = (string) $this->scalar(
            "INSERT INTO users (email, name, role, status, account_kind, guardian_user_id) VALUES ('kid@example.com', 'Kid', 'player', 'inactive', 'child', :g) RETURNING id",
            ['g' => $this->parent],
        );
        $this->pdo->prepare('UPDATE player_profiles SET login_user_id = :k WHERE id = :c')->execute(['k' => $kid, 'c' => $this->mia]);

        self::assertStringContainsString('badge--inactive">Inactive', $this->body($this->get('/trainer/players')));
    }

    #[Group('AC-58')]
    public function testAnAcceptedCoachAppearsInTheTrainersCoachesList(): void
    {
        $this->invite('coach@example.com', ['name' => 'Casey Coach']);
        $token = $this->tokenFor('coach@example.com');
        $trainerEmail = $this->trainerEmail();
        $this->signOut();
        $this->post('/invitations/' . $token, ['name' => 'Casey Coach', 'password' => self::COACH_PASSWORD]);
        $this->signOut();
        $this->login($trainerEmail);

        $body = $this->body($this->get('/trainer/coaches'));

        self::assertMatchesRegularExpression('/Your Coaches.*?Casey Coach<\/a>.*?since 2030-03-01/s', $body);
        $coach = (string) $this->scalar("SELECT id FROM users WHERE email = 'coach@example.com'");
        $detail = $this->get('/trainer/coaches/' . $coach);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('coach@example.com', $this->body($detail));
    }

    #[Group('AC-74')]
    public function testAnInactiveCoachStaysInTheListMarkedInactive(): void
    {
        $coach = $this->coachUser('coach@example.com', 'Casey Coach');
        $this->pdo->prepare('INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)')->execute(['o' => $this->orgA, 'c' => $coach]);
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :c")->execute(['c' => $coach]);

        $body = $this->body($this->get('/trainer/coaches'));

        self::assertMatchesRegularExpression('/Casey Coach<\/a> <span class="badge badge--inactive">Inactive<\/span>/', $body);
    }

    public function testTheTrainerNavigationLinksToPlayersCoachesAndTheShareLink(): void
    {
        $body = $this->body($this->get('/trainer'));

        foreach (['/trainer/players', '/trainer/coaches', '/trainer/links'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $body);
        }
    }

    /** @return array{string, string} the other Trainer's user id and organization id */
    private function otherOrganization(string $business): array
    {
        $trainer = $this->createUser('trainer', 'b' . bin2hex(random_bytes(3)) . '@example.com', name: 'Ben Trainer');
        $org = (string) $this->scalar('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n) RETURNING id', ['o' => $trainer, 'n' => $business]);

        return [$trainer, $org];
    }
}
