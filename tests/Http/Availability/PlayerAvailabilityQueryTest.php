<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** The Trainer's view of player availability: filter, Best Times, advisory only (AC-63). */
final class PlayerAvailabilityQueryTest extends AvailabilityHttpTestCase
{
    private string $trainer;
    private string $org;
    private string $ann;
    private string $ben;
    private string $cat;
    private string $dan;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainer, $this->org] = $this->trainerSignedIn();
        $this->signOut();
        $parent = $this->createUser('player', 'parent@example.com', name: 'Pat Parent');
        $this->ann = $this->childOf($parent, 'Ann', 'Adams', '2018-01-01');
        $this->ben = $this->childOf($parent, 'Ben', 'Brown', '2017-01-01', 'male');
        $this->cat = $this->childOf($parent, 'Cat', 'Clark', '2016-01-01');
        $this->dan = $this->childOf($parent, 'Dan', 'Davis', '2015-01-01', 'male');
        foreach ([$this->ann, $this->ben, $this->cat, $this->dan] as $id) {
            $this->associate($id, $this->org);
        }
        // Ann: Monday evenings. Ben: Monday mornings, Tuesday "Not Available". Cat: nothing at all. Dan: only Thursdays.
        $this->slot('player_profile', $this->ann, 0, '17:00', '19:00');
        $this->slot('player_profile', $this->ben, 0, '08:00', '10:00');
        $this->notAvailable('player_profile', $this->ben, 1);
        $this->slot('player_profile', $this->dan, 3, '16:00', '18:00');
        $this->signInAsUser($this->trainer);
    }

    /** @return list<string> names in the order they appear */
    private function names(string $body): array
    {
        preg_match_all('/<h2><a href="\/trainer\/availability\/[0-9a-f-]+">([^<]+)<\/a>/', $body, $m);

        return $m[1];
    }

    #[Group('AC-63')]
    public function testEveryPlayerIsListedWithoutAFilterWithTheirBestTimes(): void
    {
        $body = $this->body($this->get('/trainer/availability'));

        self::assertSame(['Ann Adams', 'Ben Brown', 'Cat Clark', 'Dan Davis'], $this->names($body));
        self::assertMatchesRegularExpression('/Ann Adams.*?Best Times.*?Mon 17:00-19:00/s', $body);
        self::assertMatchesRegularExpression('/Dan Davis.*?Best Times.*?Thu 16:00-18:00/s', $body);
    }

    #[Group('AC-63')]
    public function testTheDayAndTimeFilterReturnsMatchingPlayersAndKeepsPlayersWithNoEntries(): void
    {
        $body = $this->body($this->get('/trainer/availability?day=0&time=17:30'));

        // Ann fits; Cat gave nothing and is kept; Dan has no Monday entry (no preference, kept); Ben's Monday is only 08:00-10:00
        self::assertSame(['Ann Adams', 'Cat Clark', 'Dan Davis'], $this->names($body));
        self::assertMatchesRegularExpression('/Ann Adams.*?<span class="badge">Available<\/span>/s', $body);
        self::assertMatchesRegularExpression('/Cat Clark.*?<span class="badge">No preference<\/span>/s', $body);
        self::assertStringNotContainsString('Ben Brown', $body);
    }

    #[Group('AC-63')]
    public function testFilteringByDayAloneAndByNotAvailableDays(): void
    {
        $monday = $this->names($this->body($this->get('/trainer/availability?day=0')));
        $tuesday = $this->names($this->body($this->get('/trainer/availability?day=1')));

        self::assertSame(['Ann Adams', 'Ben Brown', 'Cat Clark', 'Dan Davis'], $monday);
        self::assertSame(['Ann Adams', 'Cat Clark', 'Dan Davis'], $tuesday, 'Ben is explicitly Not Available on Tuesdays');
        self::assertSame(['Dan Davis', 'Ann Adams', 'Ben Brown', 'Cat Clark'], $this->names($this->body($this->get('/trainer/availability?day=3'))), 'players that fit come first');
    }

    #[Group('AC-63')]
    public function testAPlayerWithNoEntriesIsNeverExcludedByAnyFilter(): void
    {
        for ($day = 0; $day <= 6; ++$day) {
            foreach (['', '&time=03:00', '&time=23:59'] as $time) {
                self::assertContains('Cat Clark', $this->names($this->body($this->get('/trainer/availability?day=' . $day . $time))), "day $day $time");
            }
        }
    }

    #[Group('AC-63')]
    public function testABadFilterIsRefusedWithAMessageAndStillListsPlayers(): void
    {
        $time = $this->get('/trainer/availability?day=0&time=late');
        $noDay = $this->get('/trainer/availability?time=17:00');
        $badDay = $this->get('/trainer/availability?day=9');

        self::assertSame(422, $time->getStatusCode());
        self::assertStringContainsString('Use a time such as 17:30.', $this->body($time));
        self::assertCount(4, $this->names($this->body($time)));
        self::assertSame(422, $noDay->getStatusCode());
        self::assertStringContainsString('Choose a day to filter by time.', $this->body($noDay));
        self::assertSame(422, $badDay->getStatusCode());
    }

    #[Group('AC-63')]
    public function testAPlayerWhoseAssociationEndedIsNotShown(): void
    {
        $this->pdo->prepare("UPDATE player_trainer_associations SET ended_at = now(), end_reason = 'removed_by_parent' WHERE player_profile_id = :p")->execute(['p' => $this->ann]);

        self::assertSame(['Ben Brown', 'Cat Clark', 'Dan Davis'], $this->names($this->body($this->get('/trainer/availability'))));
        self::assertSame(404, $this->get('/trainer/availability/' . $this->ann)->getStatusCode());
    }

    #[Group('AC-63')]
    public function testOnePlayersWeekShowsBestTimesAndEveryDay(): void
    {
        $body = $this->body($this->get('/trainer/availability/' . $this->ben));

        self::assertStringContainsString('Availability of Ben Brown', $body);
        self::assertStringContainsString('Mon 08:00-10:00', $body);
        self::assertMatchesRegularExpression('/Tuesday<\/th>\s*<td>\s*Not Available/', $body);
        self::assertMatchesRegularExpression('/Wednesday<\/th>\s*<td>\s*No preference/', $body);
        self::assertStringContainsString('No preference given.', $this->body($this->get('/trainer/availability/' . $this->cat)));
    }

    #[Group('AC-63')]
    public function testOnlyTheTrainerRoleReachesThePages(): void
    {
        $this->signInAsCoachUser($this->coachIn($this->org));
        $asCoach = $this->get('/trainer/availability')->getStatusCode();
        $this->signInAs('player');
        $asPlayer = $this->get('/trainer/availability')->getStatusCode();
        self::assertSame([403, 403], [$asCoach, $asPlayer]);
    }

    #[Group('AC-63'), Group('AC-88')]
    public function testTheNumberOfStatementsDoesNotGrowWithThePageSize(): void
    {
        $measure = function (): int {
            CountingStatement::$executed = 0;
            $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
            try {
                self::assertSame(200, $this->get('/trainer/availability?day=0&time=17:30')->getStatusCode());
            } finally {
                $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
            }

            return CountingStatement::$executed;
        };
        $this->get('/trainer/availability'); // warm up (session, context)
        $small = $measure();

        $parent = $this->createUser('player', 'bulk@example.com', name: 'Bulk Parent');
        for ($i = 0; $i < 40; ++$i) {
            $id = $this->childOf($parent, 'Kid' . $i, 'Bulk', '2015-01-01');
            $this->associate($id, $this->org);
            $this->slot('player_profile', $id, $i % 7, '10:00', '11:00');
        }
        $large = $measure();

        self::assertSame($small, $large, 'statement count must be constant in the number of players');
        self::assertCount(44, $this->names($this->body($this->get('/trainer/availability'))));
    }
}
