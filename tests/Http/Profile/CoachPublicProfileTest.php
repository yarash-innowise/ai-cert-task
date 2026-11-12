<?php

declare(strict_types=1);

namespace Tests\Http\Profile;

use PHPUnit\Framework\Attributes\Group;

/**
 * AC-68: a Coach's public profile. The Coach and their Trainer always see it; players and parents under that Trainer
 * only when the Coach ticked "public"; everybody else, including people of another organization, gets 404 (AC-10).
 */
final class CoachPublicProfileTest extends ProfileHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $coach;
    private string $coachEmail;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Org A');
        [, $this->orgB] = $this->trainerWithOrganization('Org B');
        $this->coach = $this->signInAsCoachOf($this->orgA);
        $this->coachEmail = (string) $this->user($this->coach)['email'];
        $this->pdo->prepare("UPDATE users SET name = 'Casey Coach' WHERE id = :u")->execute(['u' => $this->coach]);
        $this->saveProfile(['name' => 'Casey Coach', 'bio' => "Played pro.\nLoves teaching <b>kids</b>.", 'credentials' => 'Level 4', 'certifications' => "USPTA\nPTR"]);
    }

    private function makePublic(bool $public): void
    {
        $this->pdo->prepare('UPDATE coach_profiles SET is_public = :p WHERE user_id = :u')->execute(['p' => $public ? 't' : 'f', 'u' => $this->coach]);
    }

    private function signInAsTrainerOf(string $organizationId): void
    {
        $email = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $organizationId]);
        $this->cookies = [];
        $this->login($email);
    }

    public function testTheCoachSeesTheirOwnProfileEvenWhenItIsNotPublic(): void
    {
        $this->makePublic(false);
        $this->cookies = [];
        $this->login($this->coachEmail);

        $response = $this->get('/coaches/' . $this->coach);
        $html = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Casey Coach</h1>', $html);
        self::assertStringContainsString('This profile is not public', $html);
        self::assertStringContainsString('Level 4', $html);
        self::assertStringContainsString('<li>USPTA</li>', $html);
        self::assertStringContainsString('<li>PTR</li>', $html);
        self::assertStringContainsString('Coach at Org A', $html);
    }

    public function testTheBioIsEscapedSoMarkupCannotInjectScript(): void
    {
        $this->makePublic(true);
        $this->signInAsParentOf($this->orgA);

        $html = $this->body($this->get('/coaches/' . $this->coach));

        self::assertStringContainsString('Loves teaching &lt;b&gt;kids&lt;/b&gt;.', $html);
        self::assertStringNotContainsString('<b>kids</b>', $html);
        self::assertStringContainsString('Played pro.<br />', $html);
    }

    public function testTheTrainerOfTheOrganizationAlwaysSeesTheProfile(): void
    {
        $this->makePublic(false);
        $this->signInAsTrainerOf($this->orgA);

        self::assertSame(200, $this->get('/coaches/' . $this->coach)->getStatusCode());
    }

    public function testPlayersAndParentsOfTheOrganizationSeeItOnlyWhenPublic(): void
    {
        $this->signInAsParentOf($this->orgA);

        $this->makePublic(false);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'private');

        $this->makePublic(true);
        $response = $this->get('/coaches/' . $this->coach);
        self::assertSame(200, $response->getStatusCode(), 'public');
        self::assertStringContainsString('Level 4', $this->body($response));
    }

    #[Group('AC-10')]
    public function testPeopleOfAnotherOrganizationGetNotFoundEvenWhenPublic(): void
    {
        $this->makePublic(true);

        $this->signInAsTrainerOf($this->orgB);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'another Trainer');

        $this->signInAsCoachOf($this->orgB);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'a Coach of another organization');

        $this->signInAsParentOf($this->orgB);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'a parent of another organization');

        $this->signInAs('player');
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'a player without a Trainer');
    }

    public function testAParentOfTwoTrainersSeesTheCoachOnlyUnderTheCoachsOrganization(): void
    {
        $this->makePublic(true);
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $this->associate($profile, $this->orgA);
        $this->associate($profile, $this->orgB);

        $this->post('/family/context', ['context' => $this->orgA . ':' . $profile]);
        self::assertSame(200, $this->get('/coaches/' . $this->coach)->getStatusCode());
        $this->post('/family/context', ['context' => $this->orgB . ':' . $profile]);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode());
    }

    public function testASuperAdminMayOpenAnyCoachProfile(): void
    {
        $this->makePublic(false);
        $this->signInAs('super_admin');

        self::assertSame(200, $this->get('/coaches/' . $this->coach)->getStatusCode());
    }

    public function testOnlyActiveCoachesAreFoundAndUnknownOrMalformedIdsAreNotFound(): void
    {
        $this->makePublic(true);
        $trainerUser = (string) $this->scalar('SELECT owner_user_id FROM organizations WHERE id = :o', ['o' => $this->orgA]);
        $this->signInAsTrainerOf($this->orgA);

        self::assertSame(404, $this->get('/coaches/' . $trainerUser)->getStatusCode(), 'a user who is not a Coach');
        self::assertSame(404, $this->get('/coaches/00000000-0000-7000-8000-000000000000')->getStatusCode());
        self::assertSame(404, $this->get('/coaches/not-a-uuid')->getStatusCode());
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :u")->execute(['u' => $this->coach]);
        self::assertSame(404, $this->get('/coaches/' . $this->coach)->getStatusCode(), 'an inactive Coach');
    }

    public function testACoachWithoutAProfileShowsAnEmptyMessageToTheirTrainer(): void
    {
        $other = $this->createUser('coach', 'bare@example.com', name: 'Bare Coach');
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id, status) VALUES (:o, :c, 'active')")->execute(['o' => $this->orgA, 'c' => $other]);
        $this->signInAsTrainerOf($this->orgA);

        self::assertStringContainsString('has not added profile details yet', $this->body($this->get('/coaches/' . $other)));
    }

    public function testThePhotoOfAPublicCoachIsVisibleToTheOrganizationButNotToOthers(): void
    {
        $this->cookies = [];
        $this->login($this->coachEmail);
        $this->saveProfile(['name' => 'Casey Coach', 'is_public' => '1'], ['photo' => $this->photoUpload()]);

        $this->signInAsParentOf($this->orgA);
        self::assertSame(200, $this->get('/media/user-thumb/' . $this->coach)->getStatusCode());
        self::assertStringContainsString('/media/user-thumb/' . $this->coach, $this->body($this->get('/coaches/' . $this->coach)));

        $this->makePublic(false);
        self::assertSame(404, $this->get('/media/user-thumb/' . $this->coach)->getStatusCode(), 'private');

        $this->makePublic(true);
        $this->signInAsParentOf($this->orgB);
        self::assertSame(404, $this->get('/media/user-thumb/' . $this->coach)->getStatusCode(), 'another organization');
    }
}
