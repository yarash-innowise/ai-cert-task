<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

final class SaveAvailabilityTest extends AvailabilityHttpTestCase
{
    private string $parent;
    private string $mia;
    private string $leo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = $this->createUser('player', 'parent@example.com', name: 'Pat Parent');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Smith', '2018-05-01');
        $this->leo = $this->childOf($this->parent, 'Leo', 'Smith', '2016-02-01', 'male');
        $this->cookies = [];
        $this->login('parent@example.com');
    }

    #[Group('AC-61')]
    public function testAParentSavesRangesAndNotAvailableDaysForAChild(): void
    {
        $response = $this->post('/family/availability/' . $this->mia, $this->week([
            0 => [['17:00', '19:00'], ['20:00', '21:00']],
            1 => 'unavailable',
            2 => 'none',
            5 => [['09:30', '11:00']],
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(3, $this->slotCount('player_profile', $this->mia));
        self::assertSame(
            [1],
            array_map(static fn(array $r): int => (int) $r['weekday'], $this->rows('SELECT weekday FROM availability_unavailable_days WHERE subject_id = :s', ['s' => $this->mia])),
        );
        $form = $this->body($this->get('/family/availability/' . $this->mia));
        self::assertStringContainsString('value="17:00"', $form);
        self::assertStringContainsString('value="09:30"', $form);
        self::assertMatchesRegularExpression('/value="unavailable" checked/', $form);
    }

    #[Group('AC-61'), Group('AC-32')]
    public function testAParentSetsEachChildSeparately(): void
    {
        $this->post('/family/availability/' . $this->mia, $this->week([0 => [['17:00', '19:00']]]));
        $this->post('/family/availability/' . $this->leo, $this->week([3 => [['16:00', '17:30']], 4 => 'unavailable']));

        self::assertSame(1, $this->slotCount('player_profile', $this->mia));
        self::assertSame(1, $this->slotCount('player_profile', $this->leo));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM availability_slots WHERE subject_id = :s AND weekday = 3', ['s' => $this->mia]));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM availability_unavailable_days WHERE subject_id = :s', ['s' => $this->mia]));
        // saving Leo's week again leaves Mia's alone
        $this->post('/family/availability/' . $this->leo, $this->week([]));
        self::assertSame(1, $this->slotCount('player_profile', $this->mia));
        self::assertSame(0, $this->slotCount('player_profile', $this->leo));
    }

    #[Group('AC-61')]
    public function testSavingReplacesTheWholeWeek(): void
    {
        $this->post('/family/availability/' . $this->mia, $this->week([0 => [['17:00', '19:00']], 1 => 'unavailable']));
        $this->post('/family/availability/' . $this->mia, $this->week([2 => [['10:00', '11:00']]]));

        self::assertSame(1, $this->slotCount('player_profile', $this->mia));
        self::assertSame(2, (int) $this->scalar('SELECT weekday FROM availability_slots WHERE subject_id = :s', ['s' => $this->mia]));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM availability_unavailable_days WHERE subject_id = :s', ['s' => $this->mia]));
    }

    #[Group('AC-62')]
    public function testSavingShowsTheExactConfirmation(): void
    {
        $response = $this->post('/family/availability/' . $this->mia, $this->week([0 => [['17:00', '19:00']]]));
        $page = $this->get($this->location($response));

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Availability saved. Trainers can see these preferences when planning sessions.', $this->body($page));
    }

    #[Group('AC-61'), Group('AC-62')]
    public function testAnAdultPlayerSetsTheirOwnWeek(): void
    {
        $adult = $this->createUser('player', 'adult@example.com', name: 'Alex Adult');
        $self = $this->selfOf($adult, 'Alex', 'Adult', '1990-01-15');
        $this->cookies = [];
        $this->login('adult@example.com');

        $response = $this->post('/family/availability/' . $self, $this->week([6 => [['08:00', '10:00']]]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->slotCount('player_profile', $self));
    }

    #[Group('AC-61')]
    public function testTheIndexListsEveryProfileOfTheFamily(): void
    {
        $this->selfOf($this->parent);
        $body = $this->body($this->get('/family/availability'));

        self::assertStringContainsString('href="/family/availability/' . $this->mia . '"', $body);
        self::assertStringContainsString('href="/family/availability/' . $this->leo . '"', $body);
        self::assertStringContainsString('Pat Parent (you)', $body);
    }

    #[Group('AC-61')]
    public function testEndBeforeStartIsRefusedNamingTheProblem(): void
    {
        $response = $this->post('/family/availability/' . $this->mia, $this->week([0 => [['19:00', '17:00']]]));
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Monday: 19:00 to 17:00 - the end time must be after the start time.', $body);
        self::assertStringContainsString('value="19:00"', $body, 'what was typed is kept');
        self::assertSame(0, $this->slotCount('player_profile', $this->mia));
    }

    #[Group('AC-61')]
    public function testOverlappingRangesAreRefusedNamingTheProblemAndKeepTheOldWeek(): void
    {
        $this->post('/family/availability/' . $this->mia, $this->week([4 => [['08:00', '09:00']]]));

        $response = $this->post('/family/availability/' . $this->mia, $this->week([1 => [['17:00', '19:00'], ['18:00', '20:00']]]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Tuesday: 17:00-19:00 overlaps 18:00-20:00.', $this->body($response));
        self::assertSame(1, $this->slotCount('player_profile', $this->mia));
        self::assertSame(4, (int) $this->scalar('SELECT weekday FROM availability_slots WHERE subject_id = :s', ['s' => $this->mia]), 'the previous week is untouched');
    }

    #[Group('AC-61')]
    public function testAnAvailableDayWithoutTimesIsRefused(): void
    {
        $response = $this->post('/family/availability/' . $this->mia, ['days' => [0 => ['status' => 'available', 'slots' => [['start' => '', 'end' => '']]]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Monday: add a time range', $this->body($response));
    }

    #[Group('AC-61')]
    public function testHalfFilledAndMalformedTimesAreRefused(): void
    {
        $half = $this->post('/family/availability/' . $this->mia, ['days' => [0 => ['status' => 'available', 'slots' => [['start' => '17:00', 'end' => '']]]]]);
        $bad = $this->post('/family/availability/' . $this->mia, ['days' => [0 => ['status' => 'available', 'slots' => [['start' => 'soon', 'end' => '18:00']]]]]);

        self::assertSame(422, $half->getStatusCode());
        self::assertStringContainsString('fill in both the start and the end time', $this->body($half));
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Use a time such as 17:30.', $this->body($bad));
    }

    #[Group('AC-61')]
    public function testTheDatabaseItselfRejectsOverlapAndBackwardsRanges(): void
    {
        $this->slot('player_profile', $this->mia, 0, '17:00', '19:00');

        foreach ([[0, '18:00', '20:00'], [0, '16:00', '17:30'], [0, '17:30', '18:00']] as [$day, $start, $end]) {
            $this->pdo->exec('SAVEPOINT s1');
            try {
                $this->slot('player_profile', $this->mia, $day, $start, $end);
                self::fail("$start-$end overlaps 17:00-19:00 and must be refused");
            } catch (\PDOException $e) {
                self::assertSame('23P01', $e->getCode());
            }
            $this->pdo->exec('ROLLBACK TO SAVEPOINT s1');
        }
        // adjacent ranges and other days / other subjects are fine
        $this->slot('player_profile', $this->mia, 0, '19:00', '20:00');
        $this->slot('player_profile', $this->mia, 1, '17:00', '19:00');
        $this->slot('player_profile', $this->leo, 0, '17:00', '19:00');

        $this->pdo->exec('SAVEPOINT s2');
        try {
            $this->slot('player_profile', $this->mia, 2, '12:00', '11:00');
            self::fail('an end before the start must be refused');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->getCode());
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT s2');
    }

    #[Group('AC-61')]
    public function testAPlayerWithNoEntriesStaysWithoutPreferenceRows(): void
    {
        $this->post('/family/availability/' . $this->mia, $this->week([0 => 'none', 1 => 'none']));

        self::assertSame(0, $this->slotCount('player_profile', $this->mia));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM availability_unavailable_days WHERE subject_id = :s', ['s' => $this->mia]));
        self::assertStringContainsString('Availability saved.', $this->body($this->get('/family/availability/' . $this->mia . '?notice=saved')));
    }
}
