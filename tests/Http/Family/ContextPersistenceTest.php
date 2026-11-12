<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class ContextPersistenceTest extends FamilyHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $mia;
    private string $parent;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $this->orgB] = $this->trainerWithOrganization('Baseline Club');
        $this->parent = $this->signInAs('player');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Parent');
        $this->associate($this->mia, $this->orgA, '2030-01-01 00:00:00+00');
        $this->associate($this->mia, $this->orgB, '2030-01-02 00:00:00+00');
    }

    private function showing(string $page = '/family/content'): string
    {
        preg_match('#Showing: ([^<]+)<#', $this->body($this->get($page)), $m);

        return $m[1] ?? '';
    }

    #[Group('AC-25')]
    public function testTheChosenContextSurvivesEveryLaterRequestOfTheSession(): void
    {
        $default = $this->showing();
        $switch = $this->post('/family/context', ['context' => $this->orgB . ':' . $this->mia]);

        self::assertSame(302, $switch->getStatusCode());
        $afterSwitch = $this->showing();
        self::assertSame('Mia Parent with Baseline Club', $afterSwitch);
        foreach (['/family/events', '/family/progress', '/family/tokens', '/family/context', '/family/children', '/family/content'] as $page) {
            $this->get($page);
        }
        $afterNavigation = $this->showing();
        self::assertSame('Mia Parent with Baseline Club', $afterNavigation);
        self::assertSame($this->orgB . ':' . $this->mia, $this->storedContext());
        self::assertSame('Mia Parent with Ace Tennis Academy', $default, 'the first valid context is the default');
    }

    #[Group('AC-25')]
    public function testTheChoiceIsKeptPerSessionAndANewSignInStartsAtTheDefault(): void
    {
        $this->post('/family/context', ['context' => $this->orgB . ':' . $this->mia]);
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $this->parent]);

        $this->cookies = [];
        $this->login($email);

        self::assertSame('Mia Parent with Ace Tennis Academy', $this->showing());
    }

    #[Group('AC-25')]
    public function testARemovedAssociationStopsBeingTheContextOnTheVeryNextRequest(): void
    {
        $this->post('/family/context', ['context' => $this->orgB . ':' . $this->mia]);
        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p AND organization_id = :o')->execute(['p' => $this->mia, 'o' => $this->orgB]);

        self::assertSame('Mia Parent with Ace Tennis Academy', $this->showing());
    }

    #[Group('AC-25')]
    public function testTheSwitchReturnsToThePageTheParentCameFrom(): void
    {
        $response = $this->post('/family/context', ['context' => $this->orgB . ':' . $this->mia], ['Referer' => 'https://app.test/family/progress']);
        $noReferer = $this->post('/family/context', ['context' => $this->orgA . ':' . $this->mia]);

        self::assertSame('/family/progress', $this->location($response));
        self::assertSame('/family/events', $this->location($noReferer));
    }
}
