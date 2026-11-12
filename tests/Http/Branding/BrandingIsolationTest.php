<?php

declare(strict_types=1);

namespace Tests\Http\Branding;

use PHPUnit\Framework\Attributes\Group;

/**
 * AC-83: saved branding reaches every user acting under that Trainer immediately, and nobody else.
 * The branding comes from the validated request context, so there is no address to forge.
 */
#[Group('AC-83'), Group('AC-10')]
final class BrandingIsolationTest extends BrandingHttpTestCase
{
    private string $orgA;
    private string $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAsTrainer('Org A');
        $this->orgA = $this->orgId;
        $this->saveBranding(['brand_color' => '#AA3300'], ['logo' => $this->logoUpload()]);
        [, $this->orgB] = $this->trainerWithOrganization('Org B');
    }

    private function assertHasBrandOfA(string $html, string $who): void
    {
        self::assertStringContainsString('--brand-primary: #AA3300', $html, $who);
        self::assertStringContainsString('/media/logo/' . $this->orgA, $html, $who);
    }

    private function assertHasNoBrandOfA(string $html, string $who): void
    {
        self::assertStringNotContainsString('#AA3300', $html, $who);
        self::assertStringNotContainsString('170, 51, 0', $html, $who);
        self::assertStringNotContainsString('/media/logo/' . $this->orgA, $html, $who);
        self::assertStringNotContainsString('Org A', $html, $who);
    }

    public function testSavedBrandingIsVisibleAtOnceToEveryoneInTheOrganization(): void
    {
        $this->cookies = [];
        $this->login($this->trainerEmail);
        $this->assertHasBrandOfA($this->body($this->get('/trainer')), 'Trainer');

        $this->signInAsCoachOf($this->orgA);
        $this->assertHasBrandOfA($this->body($this->get('/coach')), 'Coach');

        $this->signInAsParentOf($this->orgA);
        $this->assertHasBrandOfA($this->body($this->get('/family')), 'parent');
    }

    public function testNewBrandingShowsImmediatelyOnTheNextRequestOfAnotherUser(): void
    {
        $this->signInAsParentOf($this->orgA);
        $this->assertHasBrandOfA($this->body($this->get('/family')), 'before');
        $parentCookies = $this->cookies;

        $this->cookies = [];
        $this->login($this->trainerEmail);
        $this->saveBranding(['brand_color' => '#0000CC']);

        $this->cookies = $parentCookies;
        $html = $this->body($this->get('/family'));
        self::assertStringContainsString('--brand-primary: #0000CC', $html);
        self::assertStringNotContainsString('#AA3300', $html);
    }

    public function testUsersOfAnotherOrganizationNeverReceiveTheBrandVariablesOrLogo(): void
    {
        $emailB = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $this->orgB]);
        $this->cookies = [];
        $this->login($emailB);
        $this->assertHasNoBrandOfA($this->body($this->get('/trainer')), 'Trainer B');
        $this->assertHasNoBrandOfA($this->body($this->get('/trainer/branding')), 'Trainer B branding page');

        $this->signInAsCoachOf($this->orgB);
        $this->assertHasNoBrandOfA($this->body($this->get('/coach')), 'Coach B');

        $this->signInAsParentOf($this->orgB);
        $this->assertHasNoBrandOfA($this->body($this->get('/family')), 'parent B');
    }

    public function testUsersWithoutAnOrganizationAndVisitorsGetThePlatformDefault(): void
    {
        $this->signInAs('super_admin');
        $this->assertHasNoBrandOfA($this->body($this->get('/admin')), 'Super Admin');

        $this->signInAs('player');
        $this->assertHasNoBrandOfA($this->body($this->get('/family')), 'player without a Trainer');

        $this->cookies = [];
        $this->assertHasNoBrandOfA($this->body($this->get('/login')), 'visitor');
    }

    public function testAParentOfTwoTrainersSeesTheBrandOfTheActiveContextOnly(): void
    {
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $this->associate($profile, $this->orgA);
        $this->associate($profile, $this->orgB);

        $this->post('/family/context', ['context' => $this->orgA . ':' . $profile]);
        $this->assertHasBrandOfA($this->body($this->get('/family')), 'context A');

        $this->post('/family/context', ['context' => $this->orgB . ':' . $profile]);
        $html = $this->body($this->get('/family'));
        self::assertStringNotContainsString('#AA3300', $html, 'context B');
        self::assertStringNotContainsString('/media/logo/' . $this->orgA, $html, 'context B');
    }

    public function testAnotherTrainerCannotFetchOrChangeTheLogoOfOrgA(): void
    {
        $before = $this->brandingRow($this->orgA);
        $emailB = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $this->orgB]);
        $this->cookies = [];
        $this->login($emailB);

        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'logo of a foreign organization');

        // A forged organization id in the body is ignored: the change lands on the Trainer's own organization.
        $this->saveBranding(['brand_color' => '#00FF00', 'organization_id' => $this->orgA], ['logo' => $this->logoUpload()]);
        self::assertSame($before, $this->brandingRow($this->orgA));
        self::assertSame('#00FF00', $this->brandingRow($this->orgB)['brand_color']);
    }
}
