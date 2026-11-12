<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use PHPUnit\Framework\Attributes\Group;

final class LinkUsageTest extends OnboardingHttpTestCase
{
    #[Group('AC-23')]
    public function testEachUseRecordsTheLinkTheUserTheProfileAndTheTime(): void
    {
        $parent = $this->signInAs('player');
        $mia = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization();
        [$linkId, $code] = $this->shareLink($org);

        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => $code]);

        $use = $this->row('SELECT * FROM share_link_uses');
        self::assertIsArray($use);
        self::assertSame($linkId, $use['share_link_id']);
        self::assertSame($parent, $use['user_id']);
        self::assertSame($mia, $use['player_profile_id']);
        self::assertSame('profile_added', $use['outcome']);
        self::assertSame('2030-03-01 10:00:00+00', $use['used_at']);
        $link = $this->row('SELECT use_count, last_used_at FROM share_links WHERE id = :i', ['i' => $linkId]);
        self::assertSame(1, $link['use_count'] ?? null);
        self::assertSame('2030-03-01 10:00:00+00', $link['last_used_at'] ?? null);
    }

    #[Group('AC-23')]
    public function testTheCountGrowsWithEveryAssociationAndLastUsedMovesForward(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization();
        [$linkId, $code] = $this->shareLink($org);
        $children = [$this->childOf($parent, 'Mia'), $this->childOf($parent, 'Sam', 'Jones', '2019-01-01', 'male'), $this->childOf($parent, 'Zoe', 'Brown', '2018-01-01')];

        foreach ($children as $child) {
            $this->clock->advance('1 hour');
            $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code]);
        }

        self::assertSame(3, $this->scalar('SELECT use_count FROM share_links WHERE id = :i', ['i' => $linkId]));
        self::assertSame('2030-03-01 13:00:00+00', $this->scalar('SELECT last_used_at FROM share_links WHERE id = :i', ['i' => $linkId]));
        self::assertSame(3, (int) $this->scalar('SELECT count(*) FROM share_link_uses WHERE share_link_id = :i', ['i' => $linkId]));
        self::assertSame(3, (int) $this->scalar('SELECT count(DISTINCT player_profile_id) FROM share_link_uses WHERE share_link_id = :i', ['i' => $linkId]));
    }

    #[Group('AC-23')]
    public function testTheTrainerSeesTheCountAndLastUseOnTheirLinkPage(): void
    {
        $parent = $this->createUser('player', 'parent@example.com');
        $mia = $this->childOf($parent);
        [$trainer, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->cookies = [];
        $this->login('parent@example.com');
        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => $code]);
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $trainer]);
        $this->cookies = [];
        $this->login($email);

        $page = $this->body($this->get('/trainer/links'));

        self::assertStringContainsString('Used 1 time', $page);
        self::assertStringContainsString('last used 2030-03-01 10:00 UTC', $page);
    }

    #[Group('AC-23')]
    #[Group('AC-26')]
    public function testAnUnusableOrRepeatedCodeCountsNothing(): void
    {
        $parent = $this->signInAs('player');
        $mia = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization();
        [$linkId, $code] = $this->shareLink($org);
        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => $code]);

        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => $code]); // already associated
        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => str_repeat('Z', 22)]); // unknown
        $this->get('/join/' . $code); // opening the page is not a use

        self::assertSame(1, $this->scalar('SELECT use_count FROM share_links WHERE id = :i', ['i' => $linkId]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_link_uses'));
    }
}
