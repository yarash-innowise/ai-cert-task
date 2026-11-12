<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use PHPUnit\Framework\Attributes\Group;

final class JoinAsPlayerTest extends OnboardingHttpTestCase
{
    /** @return array{string, string, string} organization id, link id, code */
    private function trainerLink(string $business = 'Ace Tennis Academy'): array
    {
        [, $org] = $this->trainerWithOrganization($business);
        [$linkId, $code] = $this->shareLink($org);

        return [$org, $linkId, $code];
    }

    #[Group('AC-21')]
    public function testALoggedInPlayerIsAssociatedInstantlyAndTakenToThatTrainersEvents(): void
    {
        [$org, $linkId, $code] = $this->trainerLink();
        $player = $this->signInAs('player');
        $me = $this->selfOf($player);
        $users = (int) $this->scalar('SELECT count(*) FROM users');

        $response = $this->post('/join/' . $code);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/events?notice=joined', $this->location($response));
        self::assertSame($org, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $me]));
        self::assertSame($linkId, $this->scalar('SELECT source_share_link_id FROM player_trainer_associations WHERE player_profile_id = :p', ['p' => $me]));
        self::assertSame($users, (int) $this->scalar('SELECT count(*) FROM users'), 'no duplicate account');
        self::assertSame($org . ':' . $me, $this->storedContext());
        $events = $this->body($this->get('/family/events?notice=joined'));
        self::assertStringContainsString('You are now associated with this Trainer.', $events);
        self::assertStringContainsString('Showing events of Ace Tennis Academy', $events);
    }

    #[Group('AC-21'), Group('AC-23')]
    public function testTheJoinIsRecordedAsALinkUse(): void
    {
        [, $linkId, $code] = $this->trainerLink();
        $player = $this->signInAs('player');
        $me = $this->selfOf($player);

        $this->post('/join/' . $code);

        $use = $this->row('SELECT * FROM share_link_uses');
        self::assertSame($linkId, $use['share_link_id'] ?? null);
        self::assertSame($player, $use['user_id'] ?? null);
        self::assertSame($me, $use['player_profile_id'] ?? null);
        self::assertSame('joined', $use['outcome'] ?? null);
        self::assertSame(1, $this->scalar('SELECT use_count FROM share_links WHERE id = :i', ['i' => $linkId]));
    }

    #[Group('AC-21')]
    public function testOpeningTheLinkOfATrainerAlreadyJoinedAddsNoDuplicateAndShowsTheNotice(): void
    {
        [$org, , $code] = $this->trainerLink();
        $player = $this->signInAs('player');
        $me = $this->selfOf($player);
        $this->post('/join/' . $code);
        $before = $this->writeFootprint();

        $again = $this->post('/join/' . $code);

        self::assertSame('/family/events?notice=already_associated', $this->location($again));
        self::assertSame($before, $this->writeFootprint(), 'nothing is written the second time');
        self::assertSame(1, $this->activeAssociations($me));
        self::assertStringContainsString('You are already associated with this Trainer.', $this->body($this->get($this->location($again))));
        self::assertSame($org . ':' . $me, $this->storedContext());
    }

    #[Group('AC-21')]
    public function testAParentWithNoChildrenAndAMeProfileIsAssociatedAloneWithoutAChecklist(): void
    {
        [, , $code] = $this->trainerLink();
        $parent = $this->signInAs('player');
        $me = $this->selfOf($parent);

        $response = $this->post('/join/' . $code);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringNotContainsString('Who will train', $this->body($response));
        self::assertSame(1, $this->activeAssociations($me));
    }

    #[Group('AC-21')]
    public function testAPlayerWithoutAnyProfileIsAskedToAddOneFirst(): void
    {
        [, , $code] = $this->trainerLink();
        $this->signInAs('player');
        $before = $this->writeFootprint();

        $response = $this->post('/join/' . $code);

        self::assertSame('/family/me', $this->location($response));
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-21')]
    public function testAVisitorWhoSignsInFromTheLinkComesBackAndIsAssociated(): void
    {
        [$org, , $code] = $this->trainerLink();
        $player = $this->createUser('player', 'p@example.com');
        $me = $this->selfOf($player);
        $this->cookies = [];

        self::assertStringContainsString('/login?return_to=' . rawurlencode('/join/' . $code), $this->body($this->get('/join/' . $code)));
        $login = $this->post('/login', ['email' => 'p@example.com', 'password' => 'correct horse battery', 'return_to' => '/join/' . $code]);
        self::assertSame('/join/' . $code, $this->location($login));
        $this->post('/join/' . $code);

        self::assertSame($org, $this->scalar('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p', ['p' => $me]));
    }

    #[Group('AC-21')]
    public function testTrainersCoachesAndSuperAdminsAreToldTheLinkIsForPlayers(): void
    {
        [, , $code] = $this->trainerLink();
        foreach (['trainer', 'coach', 'super_admin'] as $role) {
            $this->signInAs($role);
            $before = $this->writeFootprint();

            $response = $this->get('/join/' . $code);

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertStringContainsString('This link is for players', $this->body($response));
            self::assertSame($before, $this->writeFootprint(), $role);
        }
    }

    #[Group('AC-26')]
    public function testAnInvalidLinkAssociatesNobodyEvenWhenLoggedIn(): void
    {
        [, , $active] = $this->trainerLink();
        $deactivated = $this->shareLink($this->trainerWithOrganization('Gone')[1], null, false)[1];
        $inactiveOrg = $this->shareLink($this->trainerWithOrganization('Closed', 'inactive')[1])[1];
        $player = $this->signInAs('player');
        $this->selfOf($player);
        $before = $this->writeFootprint();

        foreach (['0000000000000000000000', 'nonsense', $deactivated, $inactiveOrg] as $code) {
            $response = $this->get('/join/' . $code);
            self::assertSame(404, $response->getStatusCode(), $code);
            self::assertStringContainsString('This link is not valid', $this->body($response));
            $post = $this->post('/join/' . $code . '/who', ['profile_ids' => ['anything']]);
            self::assertSame(404, $post->getStatusCode(), $code);
        }
        self::assertSame($before, $this->writeFootprint());
        self::assertNotSame('', $active);
    }
}
