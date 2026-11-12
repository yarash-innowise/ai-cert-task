<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use PHPUnit\Framework\Attributes\Group;

final class JoinFamilyChecklistTest extends OnboardingHttpTestCase
{
    private string $org;
    private string $code;
    private string $parent;
    private string $me;
    private string $mia;
    private string $sam;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->org] = $this->trainerWithOrganization();
        [, $this->code] = $this->shareLink($this->org);
        $this->parent = $this->signInAs('player');
        $this->me = $this->selfOf($this->parent);
        $this->mia = $this->childOf($this->parent, 'Mia', 'Parent');
        $this->sam = $this->childOf($this->parent, 'Sam', 'Parent', '2019-03-03', 'male');
    }

    #[Group('AC-22')]
    public function testAParentWithChildrenIsAskedWhoWillTrainWithAnUntickedChecklist(): void
    {
        $before = $this->writeFootprint();

        $response = $this->get('/join/' . $this->code);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Who will train with Ace Tennis Academy?', $body);
        foreach ([$this->me => 'Me', $this->mia => 'Mia Parent', $this->sam => 'Sam Parent'] as $id => $label) {
            self::assertMatchesRegularExpression('#<input type="checkbox" name="profile_ids\[\]" value="' . $id . '">\s*' . $label . '#', $body);
        }
        self::assertStringNotContainsString('checked', $body);
        self::assertSame($before, $this->writeFootprint(), 'asking writes nothing');
    }

    #[Group('AC-22')]
    public function testOnlyTheTickedPeopleAreAssociated(): void
    {
        $response = $this->post('/join/' . $this->code . '/who', ['profile_ids' => [$this->mia]]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/events?notice=joined', $this->location($response));
        self::assertSame(1, $this->activeAssociations($this->mia));
        self::assertSame(0, $this->activeAssociations($this->me));
        self::assertSame(0, $this->activeAssociations($this->sam));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_link_uses'));
        self::assertSame($this->org . ':' . $this->mia, $this->storedContext());
    }

    #[Group('AC-22')]
    public function testSeveralTickedPeopleAreAllAssociatedAndEachUseIsRecorded(): void
    {
        $this->post('/join/' . $this->code . '/who', ['profile_ids' => [$this->me, $this->sam]]);

        self::assertSame(1, $this->activeAssociations($this->me));
        self::assertSame(1, $this->activeAssociations($this->sam));
        self::assertSame(0, $this->activeAssociations($this->mia));
        self::assertSame(2, (int) $this->scalar('SELECT use_count FROM share_links WHERE code = :c', ['c' => $this->code]));
    }

    #[Group('AC-22')]
    public function testMeIsOnlyOfferedWhenAMeProfileExists(): void
    {
        $this->pdo->prepare('DELETE FROM player_profiles WHERE id = :i')->execute(['i' => $this->me]);

        $body = $this->body($this->get('/join/' . $this->code));

        self::assertStringNotContainsString('>Me<', preg_replace('/\s+/', '', $body) ?? '');
        self::assertStringNotContainsString('value="' . $this->me . '"', $body);
        self::assertStringContainsString('Mia Parent', $body);
    }

    #[Group('AC-22')]
    public function testUntickingEveryoneAssociatesNobodyAndExplainsWhy(): void
    {
        $before = $this->writeFootprint();

        $response = $this->post('/join/' . $this->code . '/who', []);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose at least one person, or cancel.', $this->body($response));
        self::assertStringContainsString('href="/family/"', $this->body($response));
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-22')]
    public function testSomeoneElsesProfileCannotBeTicked(): void
    {
        $other = $this->createUser('player', 'other@example.com');
        $stranger = $this->childOf($other, 'Zed', 'Other');
        $before = $this->writeFootprint();

        $response = $this->post('/join/' . $this->code . '/who', ['profile_ids' => [$this->mia, $stranger]]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame($before, $this->writeFootprint(), 'the valid tick is not applied either');
    }

    #[Group('AC-22')]
    public function testAnAlreadyAssociatedPersonIsShownAsSuchAndNeverDuplicated(): void
    {
        $this->associate($this->mia, $this->org);

        $body = $this->body($this->get('/join/' . $this->code));
        $this->post('/join/' . $this->code . '/who', ['profile_ids' => [$this->mia, $this->sam]]);

        self::assertMatchesRegularExpression('#value="' . $this->mia . '" disabled>#', $body);
        self::assertStringContainsString('already with Ace Tennis Academy', $body);
        self::assertSame(1, $this->activeAssociations($this->mia));
        self::assertSame(1, $this->activeAssociations($this->sam));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_link_uses'), 'only the new association is a use');
    }

    #[Group('AC-21')]
    public function testWhenEveryoneAlreadyBelongsTheParentGetsTheAlreadyAssociatedNotice(): void
    {
        foreach ([$this->me, $this->mia, $this->sam] as $id) {
            $this->associate($id, $this->org);
        }
        $before = $this->writeFootprint();

        $response = $this->post('/join/' . $this->code);

        self::assertSame('/family/events?notice=already_associated', $this->location($response));
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-22')]
    public function testAChildLoginCannotAnswerTheChecklist(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);
        $before = $this->writeFootprint();

        $response = $this->post('/join/' . $this->code . '/who', ['profile_ids' => [$this->mia]]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, $this->writeFootprint());
    }
}
