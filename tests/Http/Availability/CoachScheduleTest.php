<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** A Coach's recurring weekly schedule, "My Times" (AC-64). */
final class CoachScheduleTest extends AvailabilityHttpTestCase
{
    private string $trainer;
    private string $org;
    private string $coachA;
    private string $coachB;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainer, $this->org] = $this->trainerSignedIn();
        $this->signOut();
        $this->coachA = $this->coachIn($this->org, 'a@example.com', 'Ada Coach');
        $this->coachB = $this->coachIn($this->trainerWithOrganization('Beta Padel Club')[1], 'b@example.com', 'Bob Coach');
        $this->signInAsCoachUser($this->coachA);
    }

    #[Group('AC-64')]
    public function testSeveralSlotsPerWeekdayAreSaved(): void
    {
        $response = $this->post('/coach/schedule', $this->week([
            0 => [['08:00', '10:00'], ['12:00', '14:00'], ['18:00', '20:30']],
            2 => [['09:00', '11:00']],
            3 => 'unavailable',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/coach/schedule?notice=saved', $this->location($response));
        self::assertSame(4, $this->slotCount('coach', $this->coachA));
        self::assertSame(3, (int) $this->scalar("SELECT count(*) FROM availability_slots WHERE subject_id = :c AND weekday = 0", ['c' => $this->coachA]));
        $page = $this->body($this->get('/coach/schedule?notice=saved'));
        self::assertStringContainsString('Your schedule was saved.', $page);
        self::assertStringContainsString('value="18:00"', $page);
        self::assertStringContainsString('value="20:30"', $page);
    }

    #[Group('AC-64')]
    public function testEndBeforeStartIsRefusedWithAMessage(): void
    {
        $response = $this->post('/coach/schedule', $this->week([0 => [['14:00', '13:00']]]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Monday: 14:00 to 13:00 - the end time must be after the start time.', $this->body($response));
        self::assertSame(0, $this->slotCount('coach', $this->coachA));
    }

    #[Group('AC-64')]
    public function testOverlappingSlotsOnADayAreRefusedWithAMessage(): void
    {
        $this->post('/coach/schedule', $this->week([4 => [['08:00', '09:00']]]));

        $response = $this->post('/coach/schedule', $this->week([0 => [['08:00', '12:00'], ['11:00', '13:00']]]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Monday: 08:00-12:00 overlaps 11:00-13:00.', $this->body($response));
        self::assertSame(1, $this->slotCount('coach', $this->coachA), 'the saved week is untouched');
    }

    #[Group('AC-64')]
    public function testTheSameTimesOnDifferentDaysDoNotOverlap(): void
    {
        $response = $this->post('/coach/schedule', $this->week([0 => [['08:00', '12:00']], 1 => [['08:00', '12:00']]]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(2, $this->slotCount('coach', $this->coachA));
    }

    #[Group('AC-64')]
    public function testACoachOnlyEditsTheirOwnWeek(): void
    {
        $this->slot('coach', $this->coachB, 0, '09:00', '10:00');

        $this->post('/coach/schedule', $this->week([2 => [['10:00', '11:00']]]));

        self::assertSame(1, $this->slotCount('coach', $this->coachA));
        self::assertSame(1, $this->slotCount('coach', $this->coachB));
        self::assertSame(0, (int) $this->scalar('SELECT weekday FROM availability_slots WHERE subject_id = :c', ['c' => $this->coachB]), "Bob's week is unchanged");
        // the page shows Ada's own saved time and never Bob's
        $page = $this->body($this->get('/coach/schedule'));
        self::assertStringContainsString('value="10:00"', $page);
        self::assertStringNotContainsString('value="09:00"', $page);
    }

    #[Group('AC-64')]
    public function testCoachSchedulePagesAreForCoachesAndTrainersSeeOnlyTheirOwnCoaches(): void
    {
        // Coach A cannot reach the Trainer's view of coach B's schedule, and another Trainer's coach is 404 for a Trainer
        self::assertSame(403, $this->get('/trainer/coaches/' . $this->coachB . '/schedule')->getStatusCode());
        $this->slot('coach', $this->coachB, 0, '09:00', '10:00');
        $this->slot('coach', $this->coachA, 1, '09:00', '10:00');

        $this->signInAsUser($this->trainer);
        self::assertSame(404, $this->get('/trainer/coaches/' . $this->coachB . '/schedule')->getStatusCode());
        $own = $this->get('/trainer/coaches/' . $this->coachA . '/schedule');
        self::assertSame(200, $own->getStatusCode());
        self::assertMatchesRegularExpression('/Tuesday<\/th>\s*<td>\s*09:00-10:00/', $this->body($own));

        $this->signInAs('player');
        self::assertSame(403, $this->get('/coach/schedule')->getStatusCode());
        self::assertSame(403, $this->post('/coach/schedule', $this->week([]))->getStatusCode());
    }

    #[Group('AC-64')]
    public function testATrainerSeesAScheduleLessCoachAsHavingNoSchedule(): void
    {
        $this->signInAsUser($this->trainer);

        self::assertStringContainsString('has not set a schedule yet', $this->body($this->get('/trainer/coaches/' . $this->coachA . '/schedule')));
    }
}
