<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class AddChildTest extends FamilyHttpTestCase
{
    #[Group('AC-27')]
    public function testAParentCreatesAChildMarkedChildAndLinkedToTheParentAccount(): void
    {
        $parent = $this->signInAs('player');

        $response = $this->addChild(['school' => 'Elm Primary']);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->row("SELECT * FROM player_profiles WHERE owner_user_id = :o", ['o' => $parent]);
        self::assertIsArray($row);
        self::assertSame('child', $row['kind']);
        self::assertSame($parent, $row['owner_user_id']);
        self::assertSame('Mia', $row['first_name']);
        self::assertSame('female', $row['gender']);
        self::assertSame('Elm Primary', $row['school']);
        self::assertStringStartsWith('/family/children/' . $row['id'] . '/trainers', $this->location($response));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'child.created' AND actor_user_id = :a", ['a' => $parent]));
    }

    #[Group('AC-27')]
    public function testSchoolAndPhotoAreOptional(): void
    {
        $parent = $this->signInAs('player');

        self::assertSame(302, $this->addChild(['school' => ''])->getStatusCode());
        self::assertNull($this->scalar('SELECT school FROM player_profiles WHERE owner_user_id = :o', ['o' => $parent]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function requiredFields(): iterable
    {
        yield 'first name' => ['first_name', 'First name is required.'];
        yield 'last name' => ['last_name', 'Last name is required.'];
        yield 'birth date' => ['birth_date', 'Birth date is required.'];
        yield 'gender' => ['gender', 'Choose a gender.'];
    }

    #[Group('AC-27')]
    #[DataProvider('requiredFields')]
    public function testEachRequiredFieldIsRefusedWithAMessageNamingIt(string $field, string $message): void
    {
        $parent = $this->signInAs('player');

        $response = $this->addChild([$field => ' ']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString($message, $this->body($response));
        self::assertStringContainsString('href="#field-' . $field . '"', $this->body($response));
        self::assertSame(0, $this->childCount($parent));
    }

    #[Group('AC-29')]
    public function testAParentWithoutTrainersIsNotPromptedAndTheChildStaysUnassociated(): void
    {
        $parent = $this->signInAs('player');
        $response = $this->addChild();

        $next = $this->get($this->location($response));

        self::assertSame(302, $next->getStatusCode());
        self::assertSame('/family/children?notice=child_created', $this->location($next));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
        self::assertSame(1, $this->childCount($parent));
    }

    #[Group('AC-29')]
    public function testAParentWithOneTrainerIsAskedYesOrNoAndYesAssociates(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        $this->associate($this->childOf($parent, 'Old', 'Sibling', '2018-01-01'), $org);

        $promptUrl = $this->location($this->addChild());
        $page = $this->get($promptUrl);

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Will Mia also train with Ace Tennis Academy?', $this->body($page));
        self::assertStringNotContainsString('type="checkbox"', $this->body($page));

        $answer = $this->post(strtok($promptUrl, '?') ?: '', ['answer' => 'yes']);

        self::assertSame(302, $answer->getStatusCode());
        $childId = (string) $this->scalar("SELECT id FROM player_profiles WHERE first_name = 'Mia'");
        self::assertSame(1, $this->activeAssociations($childId));
        self::assertSame($org, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p', ['p' => $childId]));
    }

    #[Group('AC-29')]
    public function testAnsweringNoCreatesTheChildButLeavesItUnassociated(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization();
        $this->associate($this->childOf($parent, 'Old', 'Sibling', '2018-01-01'), $org);

        $promptUrl = strtok($this->location($this->addChild()), '?') ?: '';
        $this->post($promptUrl, ['answer' => 'no']);

        $childId = (string) $this->scalar("SELECT id FROM player_profiles WHERE first_name = 'Mia'");
        self::assertSame(0, $this->activeAssociations($childId));
    }

    #[Group('AC-29')]
    public function testAParentWithSeveralTrainersSeesAChecklistAndTickedOnesAssociate(): void
    {
        $parent = $this->signInAs('player');
        [, $orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $orgB] = $this->trainerWithOrganization('Baseline Club');
        [, $orgC] = $this->trainerWithOrganization('Court Kings');
        $sibling = $this->childOf($parent, 'Old', 'Sibling', '2018-01-01');
        $this->associate($sibling, $orgA);
        $this->associate($sibling, $orgB);
        $this->associate($sibling, $orgC);

        $promptUrl = strtok($this->location($this->addChild()), '?') ?: '';
        $page = $this->get($promptUrl);

        $body = $this->body($page);
        self::assertSame(3, substr_count($body, 'type="checkbox"'));
        self::assertStringContainsString('Ace Tennis Academy', $body);
        self::assertStringContainsString('Baseline Club', $body);
        self::assertStringNotContainsString('Will Mia also train with', $body);

        $this->post($promptUrl, ['organization_ids' => [$orgA, $orgC]]);

        $childId = (string) $this->scalar("SELECT id FROM player_profiles WHERE first_name = 'Mia'");
        $statement = $this->pdo->prepare('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p');
        $statement->execute(['p' => $childId]);
        $orgs = $statement->fetchAll(\PDO::FETCH_COLUMN);
        sort($orgs);
        $expected = [$orgA, $orgC];
        sort($expected);
        self::assertSame($expected, $orgs);
    }

    #[Group('AC-29')]
    public function testTickingNoneLeavesTheChildCreatedButUnassociated(): void
    {
        $parent = $this->signInAs('player');
        [, $orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $orgB] = $this->trainerWithOrganization('Baseline Club');
        $sibling = $this->childOf($parent, 'Old', 'Sibling', '2018-01-01');
        $this->associate($sibling, $orgA);
        $this->associate($sibling, $orgB);

        $promptUrl = strtok($this->location($this->addChild()), '?') ?: '';
        $response = $this->post($promptUrl, []);

        self::assertSame(302, $response->getStatusCode());
        $childId = (string) $this->scalar("SELECT id FROM player_profiles WHERE first_name = 'Mia'");
        self::assertSame(0, $this->activeAssociations($childId));
    }

    #[Group('AC-29')]
    public function testAParentCannotAssociateAChildWithAnotherFamilysTrainerThroughThePrompt(): void
    {
        $parent = $this->signInAs('player');
        [, $known] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $stranger] = $this->trainerWithOrganization('Stranger Club');
        $this->associate($this->childOf($parent, 'Old', 'Sibling', '2018-01-01'), $known);
        $promptUrl = strtok($this->location($this->addChild()), '?') ?: '';

        $response = $this->post($promptUrl, ['organization_ids' => [$stranger]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE organization_id = :o', ['o' => $stranger]));
    }

    #[Group('AC-9')]
    public function testOnlyAPlayerAccountReachesTheAddChildRoutes(): void
    {
        $this->signInAs('trainer');

        self::assertSame(403, $this->get('/family/children/new')->getStatusCode());
        self::assertSame(403, $this->addChild()->getStatusCode());
    }

    #[Group('AC-9')]
    public function testAGuestIsSentToTheLoginPage(): void
    {
        self::assertSame(302, $this->get('/family/children/new')->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($this->get('/family/children/new')));
    }

    #[Group('AC-9')]
    public function testAnotherParentsChildCannotBeReachedThroughThePrompt(): void
    {
        $owner = $this->createUser('player', 'owner@example.com');
        $child = $this->childOf($owner);
        $this->signInAs('player');

        self::assertSame(404, $this->get('/family/children/' . $child . '/trainers')->getStatusCode());
        self::assertSame(404, $this->post('/family/children/' . $child . '/trainers', ['answer' => 'yes'])->getStatusCode());
    }
}
