<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class ParentAsPlayerTest extends FamilyHttpTestCase
{
    #[Group('AC-31')]
    public function testAParentCreatesAMeProfileAndSeesItOnTheFamilyPage(): void
    {
        $parent = $this->signInAs('player', null, 'Pat Parent');

        $response = $this->post('/family/me', ['birth_date' => '1990-04-12', 'gender' => 'other']);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->row('SELECT * FROM player_profiles WHERE owner_user_id = :o', ['o' => $parent]);
        self::assertIsArray($row);
        self::assertSame('self', $row['kind']);
        self::assertSame('Pat', $row['first_name']);
        self::assertSame('Parent', $row['last_name']);
        $page = $this->body($this->get('/family/children'));
        self::assertStringContainsString('Pat Parent', $page);
        self::assertStringContainsString('Me', $page);
    }

    #[Group('AC-31')]
    public function testTheMeProfileCanTrainWithTrainersItself(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        $this->associate($this->childOf($parent), $org);
        $this->post('/family/me', ['birth_date' => '1990-04-12']);
        $me = (string) $this->scalar("SELECT id FROM player_profiles WHERE kind = 'self'");

        $response = $this->post('/family/children/' . $me . '/trainers/add', ['organization_id' => $org]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/children?notice=added', $this->location($response));
        self::assertSame(1, $this->activeAssociations($me));
    }

    #[Group('AC-31')]
    public function testAMinorCannotBeTheAccountHolderAndOnlyOneMeProfileExists(): void
    {
        $this->signInAs('player');

        $young = $this->post('/family/me', ['birth_date' => '2015-01-01']);
        self::assertSame(422, $young->getStatusCode());
        self::assertStringContainsString('at least 18', $this->body($young));

        self::assertSame(302, $this->post('/family/me', ['birth_date' => '1990-01-01'])->getStatusCode());
        $again = $this->post('/family/me', ['birth_date' => '1990-01-01']);
        self::assertSame(422, $again->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM player_profiles WHERE kind = 'self'"));
    }
}
