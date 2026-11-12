<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class ContextSelectorTest extends FamilyHttpTestCase
{
    private string $orgA;
    private string $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $this->orgB] = $this->trainerWithOrganization('Baseline Club');
    }

    /** @return array{string, string, string, string} parent, me, mia, sam (all four contexts exist) */
    private function trainingFamily(): array
    {
        $parent = $this->signInAs('player');
        $me = $this->selfOf($parent);
        $mia = $this->childOf($parent, 'Mia', 'Parent');
        $sam = $this->childOf($parent, 'Sam', 'Parent', '2019-03-03', 'male');
        $this->associate($me, $this->orgA);
        $this->associate($mia, $this->orgA);
        $this->associate($mia, $this->orgB);
        $this->associate($sam, $this->orgB);

        return [$parent, $me, $mia, $sam];
    }

    #[Group('AC-36')]
    public function testAParentSeesYourTrainingAndYourChildrensTrainingGroups(): void
    {
        $this->trainingFamily();

        $body = $this->body($this->get('/family/context'));

        if (preg_match('~<h2 id="group-1">Your Training</h2>(.*?)<h2 id="group-2">Your Children&#039;s Training</h2>(.*)~s', $body, $m) !== 1) {
            self::fail('Both groups are expected on the page.');
        }
        self::assertStringContainsString('Ace Tennis Academy', $m[1]);
        self::assertStringNotContainsString('Mia', $m[1]);
        foreach (['Mia Parent with Ace Tennis Academy', 'Mia Parent with Baseline Club', 'Sam Parent with Baseline Club'] as $label) {
            self::assertStringContainsString($label, $m[2]);
        }
        self::assertStringNotContainsString('Sam Parent with Ace', $m[2]);
    }

    #[Group('AC-36')]
    public function testTheSelectorInTheHeaderIsANativeSelectWithGroups(): void
    {
        $this->trainingFamily();

        $body = $this->body($this->get('/family/events'));

        self::assertStringContainsString('<select class="input" id="context-select" name="context">', $body);
        self::assertStringContainsString('<optgroup label="Your Training">', $body);
        self::assertStringContainsString('<optgroup label="Your Children&#039;s Training">', $body);
        self::assertStringContainsString('<label for="context-select">', $body);
    }

    #[Group('AC-36')]
    public function testAParentWhoDoesNotTrainSeesOnlyTheChildrenSection(): void
    {
        $parent = $this->signInAs('player');
        $mia = $this->childOf($parent);
        $this->associate($mia, $this->orgA);

        $body = $this->body($this->get('/family/context'));

        self::assertStringContainsString('Your Children&#039;s Training', $body);
        self::assertStringNotContainsString('Your Training', $body);
        self::assertStringNotContainsString('>Me<', $body);
    }

    #[Group('AC-36')]
    public function testEndedAssociationsAndInactiveTrainersAreNotOffered(): void
    {
        [, $me, $mia] = $this->trainingFamily();
        $this->pdo->prepare("UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p AND organization_id = :o")->execute(['p' => $mia, 'o' => $this->orgA]);
        $this->pdo->prepare("UPDATE organizations SET status = 'inactive' WHERE id = :o")->execute(['o' => $this->orgB]);

        $body = $this->body($this->get('/family/context'));

        self::assertStringNotContainsString('Mia Parent with', $body);
        self::assertStringNotContainsString('Baseline Club', $body);
        self::assertStringContainsString('Ace Tennis Academy', $body);
        self::assertNotSame('', $me);
    }

    #[Group('AC-24')]
    public function testEachContextShowsOnlyThatTrainersData(): void
    {
        [, , $mia, $sam] = $this->trainingFamily();

        $this->post('/family/context', ['context' => $this->orgA . ':' . $mia]);
        foreach (['/family/events', '/family/content', '/family/progress', '/family/feedback', '/family/tokens'] as $page) {
            $body = $this->body($this->get($page));
            self::assertStringContainsString('Mia Parent with Ace Tennis Academy', $body, $page);
            self::assertStringNotContainsString('Baseline Club</', preg_replace('#<select.*?</select>#s', '', $body) ?? '', $page);
        }

        $this->post('/family/context', ['context' => $this->orgB . ':' . $sam]);
        $body = $this->body($this->get('/family/content'));
        self::assertMatchesRegularExpression('#Showing: Sam Parent with Baseline Club#', $body);
        self::assertDoesNotMatchRegularExpression('#Showing: Mia#', $body);
    }

    #[Group('AC-24')]
    public function testSwitchingToATrainerTheProfileIsNotAssociatedWithAnswers404(): void
    {
        [, , , $sam] = $this->trainingFamily();
        $this->post('/family/context', ['context' => $this->orgB . ':' . $sam]);
        $stored = $this->storedContext();

        $wrongPair = $this->post('/family/context', ['context' => $this->orgA . ':' . $sam]);
        [, $orgC] = $this->trainerWithOrganization('Court Three');
        $unknownOrg = $this->post('/family/context', ['context' => $orgC . ':' . $sam]);
        $garbage = $this->post('/family/context', ['context' => 'nonsense']);

        self::assertSame(404, $wrongPair->getStatusCode());
        self::assertSame(404, $unknownOrg->getStatusCode());
        self::assertSame(404, $garbage->getStatusCode());
        self::assertSame($stored, $this->storedContext());
    }

    #[Group('AC-24')]
    public function testSomeoneElsesProfileIsNotAContext(): void
    {
        $this->trainingFamily();
        $other = $this->createUser('player', 'other@example.com');
        $zed = $this->childOf($other, 'Zed', 'Other');
        $this->associate($zed, $this->orgA);

        $response = $this->post('/family/context', ['context' => $this->orgA . ':' . $zed]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Zed', $this->body($this->get('/family/context')));
    }

    #[Group('AC-24')]
    public function testTrainersHaveAFixedOrganizationAndNoSelector(): void
    {
        $email = 'owner@example.com';
        $owner = $this->createUser('trainer', $email, name: 'Owner');
        $this->pdo->prepare('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n)')->execute(['o' => $owner, 'n' => 'Owner Academy']);
        $this->cookies = [];
        $this->login($email);

        $body = $this->body($this->get('/trainer/events'));

        self::assertStringNotContainsString('context-select', $body);
        self::assertSame(403, $this->get('/family/context')->getStatusCode());
    }

    #[Group('AC-24')]
    public function testAnAnonymousVisitorCannotUseTheSelector(): void
    {
        $this->trainingFamily();
        $this->cookies = [];

        self::assertStringStartsWith('/login', $this->location($this->get('/family/context')));
    }
}
