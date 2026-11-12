<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** A Trainer sees availability of their own organization only, and nothing reads it as a blocker (AC-10, AC-63). */
final class AvailabilityIsolationTest extends AvailabilityHttpTestCase
{
    private string $trainerA;
    private string $orgA;
    private string $orgB;
    private string $miaA;
    private string $zedB;
    private string $coachB;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->trainerA, $this->orgA] = $this->trainerSignedIn('Ace Tennis Academy');
        $this->signOut();
        [, $this->orgB] = $this->trainerWithOrganization('Beta Padel Club');
        $parentA = $this->createUser('player', 'pa@example.com', name: 'Pat A');
        $parentB = $this->createUser('player', 'pb@example.com', name: 'Pat B');
        $this->miaA = $this->childOf($parentA, 'Mia', 'Alpha');
        $this->zedB = $this->childOf($parentB, 'Zed', 'Beta', '2017-01-01', 'male');
        $this->associate($this->miaA, $this->orgA);
        $this->associate($this->zedB, $this->orgB);
        $this->slot('player_profile', $this->miaA, 0, '17:00', '19:00');
        $this->slot('player_profile', $this->zedB, 0, '17:00', '19:00');
        $this->coachB = $this->coachIn($this->orgB, 'coachb@example.com', 'Bea Coach');
        $this->slot('coach', $this->coachB, 0, '09:00', '12:00');
        $this->signInAsUser($this->trainerA);
    }

    #[Group('AC-63'), Group('AC-10')]
    public function testAnotherOrganizationsPlayerIsNeverListed(): void
    {
        $body = $this->body($this->get('/trainer/availability?day=0'));

        self::assertStringContainsString('Mia Alpha', $body);
        self::assertStringNotContainsString('Zed Beta', $body);
    }

    #[Group('AC-63'), Group('AC-10')]
    public function testAnotherOrganizationsPlayerIsNotFoundLikeAMissingOne(): void
    {
        $foreign = $this->get('/trainer/availability/' . $this->zedB);
        $missing = $this->get('/trainer/availability/00000000-0000-7000-8000-000000000000');

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        $strip = static fn(string $html): string => (string) preg_replace('/nonce="[^"]*"/', '', $html);
        self::assertSame($strip($this->body($missing)), $strip($this->body($foreign)));
        self::assertSame(404, $this->get('/trainer/availability/' . $this->coachB)->getStatusCode());
        self::assertSame(404, $this->get('/trainer/availability/not-a-uuid')->getStatusCode());
    }

    #[Group('AC-10')]
    public function testAnotherOrganizationsCoachScheduleIsNotFound(): void
    {
        self::assertSame(404, $this->get('/trainer/coaches/' . $this->coachB . '/schedule')->getStatusCode());
        self::assertSame(404, $this->get('/trainer/coaches/' . $this->miaA . '/schedule')->getStatusCode());
    }

    #[Group('AC-63')]
    public function testAPlayerAssociatedWithTwoTrainersIsSeenByBothWithTheSameWeek(): void
    {
        $this->associate($this->miaA, $this->orgB);

        self::assertStringContainsString('Mon 17:00-19:00', $this->body($this->get('/trainer/availability')));
        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p AND organization_id = :o')->execute(['p' => $this->miaA, 'o' => $this->orgA]);
        self::assertSame(404, $this->get('/trainer/availability/' . $this->miaA)->getStatusCode(), 'an ended association hides the player again');
    }

    #[Group('AC-63')]
    public function testNothingOutsideTheAvailabilityModuleDependsOnAvailability(): void
    {
        // Availability is advisory: no scheduling, registration or approval code may read it as a blocker.
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/src/Availability/')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, 'App\\Availability\\') || str_contains($code, 'availability_slots') || str_contains($code, 'availability_unavailable_days')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders);
    }
}
