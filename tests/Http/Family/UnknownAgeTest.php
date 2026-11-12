<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

/**
 * Carry-over of task 40: an anonymized player has no birth date, and every reader must show the age as unknown
 * (never "0") in rosters and lists, and never fail on the missing date.
 */
#[Group('AC-76')]
final class UnknownAgeTest extends FamilyHttpTestCase
{
    private string $org;
    private string $parent;
    private string $unknown;
    private string $known;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->org] = $this->trainerWithOrganization();
        $this->parent = $this->createUser('player', 'pat@example.com', name: 'Pat Parent');
        $this->unknown = $this->childOf($this->parent, 'Mia', 'Smith', '2018-05-01');
        $this->known = $this->childOf($this->parent, 'Leo', 'Smith', '2016-01-10');
        foreach ([$this->unknown, $this->known] as $profile) {
            $this->associate($profile, $this->org);
        }
        $this->pdo->prepare('UPDATE player_profiles SET birth_date = NULL WHERE id = :p')->execute(['p' => $this->unknown]);
    }

    private function signInAsTrainerOfOrg(): void
    {
        $owner = (string) $this->scalar('SELECT owner_user_id FROM organizations WHERE id = :o', ['o' => $this->org]);
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $owner]);
        $this->cookies = [];
        $this->login($email);
    }

    public function testTheParentsChildListShowsAnUnknownAge(): void
    {
        $this->cookies = [];
        $this->login('pat@example.com');

        $response = $this->get('/family/children');
        $html = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('#Mia Smith.*?Age unknown#s', $html);
        self::assertMatchesRegularExpression('#Leo Smith.*?Age 1[0-9]#s', $html);
        self::assertStringNotContainsString('Age 0', $html);
    }

    public function testTheTrainersRosterAndPlayerPageShowAnUnknownAge(): void
    {
        $this->signInAsTrainerOfOrg();

        $list = $this->body($this->get('/trainer/players'));
        $page = $this->get('/trainer/players/' . $this->unknown);

        self::assertMatchesRegularExpression('#Mia Smith.*?<td>Unknown</td>#s', $list);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<dt>Age</dt><dd>Unknown</dd>', $this->body($page));
        self::assertStringNotContainsString('<dd>0</dd>', $this->body($page));
    }

    public function testTheAvailabilityCardShowsAnUnknownAge(): void
    {
        $this->signInAsTrainerOfOrg();

        $response = $this->get('/trainer/availability');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('age unknown', $this->body($response));
        self::assertStringNotContainsString('age 0', $this->body($response));
    }

    public function testAddingASimilarlyNamedChildDoesNotFailOnTheMissingBirthDate(): void
    {
        $this->cookies = [];
        $this->login('pat@example.com');

        $response = $this->addChild(['first_name' => 'Mia', 'last_name' => 'Smith', 'birth_date' => '2018-05-01']);

        self::assertContains($response->getStatusCode(), [200, 302], 'no failure on the child without a birth date');
    }
}
