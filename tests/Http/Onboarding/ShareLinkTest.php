<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use PHPUnit\Framework\Attributes\Group;

final class ShareLinkTest extends OnboardingHttpTestCase
{
    #[Group('AC-18')]
    public function testATrainerGeneratesAStaticPlayerLinkThatBelongsToTheirOrganization(): void
    {
        [$trainer, $org] = $this->signInAsTrainerWithOrganization();

        self::assertSame(200, $this->get('/trainer/links')->getStatusCode());
        $response = $this->post('/trainer/links/generate');

        self::assertSame('/trainer/links?notice=generated', $this->location($response));
        $row = $this->row('SELECT * FROM share_links');
        self::assertIsArray($row);
        self::assertSame($org, $row['organization_id']);
        self::assertSame('player', $row['kind']);
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{22}$/', (string) $row['code']);
        self::assertTrue($row['active']);
        self::assertSame(0, $row['use_count']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'share_link.created' AND actor_user_id = :a", ['a' => $trainer]));
        $page = $this->body($this->get('/trainer/links'));
        self::assertStringContainsString('/join/' . $row['code'], $page);
        self::assertStringContainsString('no expiry', $page);
    }

    #[Group('AC-18')]
    public function testGeneratingTwiceKeepsOneActiveLinkAndTheSameCode(): void
    {
        [, $org] = $this->signInAsTrainerWithOrganization();
        $this->post('/trainer/links/generate');
        $code = $this->codeOf($org);

        $this->post('/trainer/links/generate');

        self::assertSame($code, $this->codeOf($org));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_links'));
    }

    #[Group('AC-18')]
    public function testTheDatabaseAllowsOnlyOneActiveLinkPerOrganization(): void
    {
        [, $org] = $this->signInAsTrainerWithOrganization();
        $this->shareLink($org);

        $this->expectException(\PDOException::class);
        $this->shareLink($org);
    }

    #[Group('AC-18')]
    public function testCodesAre22CharacterBase62AndDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $code = \App\Onboarding\Domain\ShareLinkCode::generate();
            self::assertMatchesRegularExpression('/^[0-9A-Za-z]{22}$/', $code);
            $seen[$code] = true;
        }
        self::assertCount(200, $seen);
    }

    #[Group('AC-18')]
    public function testRegeneratingDeactivatesTheOldLinkAndKeepsItAsHistory(): void
    {
        [$trainer, $org] = $this->signInAsTrainerWithOrganization();
        $this->post('/trainer/links/generate');
        $old = (string) $this->codeOf($org);

        $response = $this->post('/trainer/links/regenerate');

        self::assertSame('/trainer/links?notice=regenerated', $this->location($response));
        $new = (string) $this->codeOf($org);
        self::assertNotSame($old, $new);
        self::assertFalse($this->scalar('SELECT active FROM share_links WHERE code = :c', ['c' => $old]));
        self::assertNotNull($this->scalar('SELECT deactivated_at FROM share_links WHERE code = :c', ['c' => $old]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_links WHERE active'));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'share_link.regenerated' AND actor_user_id = :a", ['a' => $trainer]));
        $this->cookies = [];
        self::assertSame(404, $this->get('/join/' . $old)->getStatusCode());
        self::assertSame(200, $this->get('/join/' . $new)->getStatusCode());
    }

    #[Group('AC-18')]
    #[Group('AC-26')]
    public function testDeactivatingTurnsTheLinkOffAndAGenerateMakesANewOne(): void
    {
        [, $org] = $this->signInAsTrainerWithOrganization();
        $this->post('/trainer/links/generate');
        $code = (string) $this->codeOf($org);

        $response = $this->post('/trainer/links/deactivate');

        self::assertSame('/trainer/links?notice=deactivated', $this->location($response));
        self::assertNull($this->codeOf($org));
        self::assertStringContainsString('do not have an active player link', $this->body($this->get('/trainer/links')));
        $this->post('/trainer/links/generate');
        self::assertNotSame($code, $this->codeOf($org));
    }

    #[Group('AC-18')]
    #[Group('AC-9')]
    public function testEachTrainerOnlyManagesTheirOwnLink(): void
    {
        [, $orgA] = $this->signInAsTrainerWithOrganization('Org A');
        $this->post('/trainer/links/generate');
        $codeA = (string) $this->codeOf($orgA);
        [, $orgB] = $this->signInAsTrainerWithOrganization('Org B');

        self::assertStringNotContainsString($codeA, $this->body($this->get('/trainer/links')));
        $this->post('/trainer/links/deactivate');
        $this->post('/trainer/links/regenerate');

        self::assertSame($codeA, $this->codeOf($orgA), "Trainer B's actions never touch Trainer A's link");
        self::assertNotNull($this->codeOf($orgB));
    }

    #[Group('AC-9')]
    public function testOnlyTrainersReachTheLinkManagementRoutes(): void
    {
        foreach (['player', 'coach', 'super_admin'] as $role) {
            $this->cookies = [];
            $this->signInAs($role);
            self::assertSame(403, $this->get('/trainer/links')->getStatusCode(), $role);
            self::assertSame(403, $this->post('/trainer/links/generate')->getStatusCode(), $role);
        }
        $this->cookies = [];
        self::assertSame(302, $this->get('/trainer/links')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM share_links'));
    }

    #[Group('AC-9')]
    public function testATrainerWithoutAnActiveOrganizationGetsNotFound(): void
    {
        $this->signInAs('trainer');

        self::assertSame(404, $this->get('/trainer/links')->getStatusCode());
        self::assertSame(404, $this->post('/trainer/links/generate')->getStatusCode());
    }

    #[Group('AC-18')]
    public function testThePlayerLinkNeverExpiresAndHasNoUseLimit(): void
    {
        [, $org] = $this->signInAsTrainerWithOrganization();
        $this->post('/trainer/links/generate');
        $code = (string) $this->codeOf($org);
        $this->clock->advance('5 years');
        $this->cookies = [];

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->get('/join/' . $code)->getStatusCode());
        }
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM information_schema.columns WHERE table_name = 'share_links' AND column_name IN ('active', 'use_count')"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM information_schema.columns WHERE table_name = 'share_links' AND column_name IN ('expires_at', 'max_uses')"));
    }
}
