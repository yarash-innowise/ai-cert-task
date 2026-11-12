<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Admin\Infrastructure\UserSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Availability\AvailabilityHttpTestCase;
use Tests\Support\PerfLog;

/** Profile saves complete in under 1 second for every role, on a database of 10,000 users (AC-88). Run with `--group perf`. */
#[Group('perf'), Group('AC-88')]
final class ProfileSaveTimingTest extends AvailabilityHttpTestCase
{
    public function testProfileSavesCompleteInUnderOneSecondForEveryRole(): void
    {
        [$trainerId, $org] = $this->trainerSignedIn();
        $coach = $this->coachIn($org);
        $player = $this->createUser('player', 'pat@example.com');
        $this->selfOf($player);
        (new UserSeeder($this->pdo))->seed(10000);
        $this->pdo->exec('ANALYZE');

        $cases = [
            'trainer' => [fn() => $this->signInAsUser($trainerId), ['business_name' => 'Ace', 'details' => 'd']],
            'coach' => [fn() => $this->signInAsCoachUser($coach), ['bio' => 'b', 'is_public' => '1']],
            'player' => [fn() => $this->signInAsUser($player), ['school' => 's', 'jersey_number' => '4', 'emergency_contact' => 'e', 'phone' => '+1 415 555 2671']],
        ];
        foreach ($cases as $role => [$signIn, $fields]) {
            $signIn();
            $started = hrtime(true);
            $response = $this->post('/profile', ['name' => 'Alex Example', ...$fields]);
            $seconds = (hrtime(true) - $started) / 1e9;
            PerfLog::record('profile save ' . $role, $seconds, '10,000 users');

            self::assertContains($response->getStatusCode(), [200, 302], $role);
            self::assertLessThan(1.0, $seconds, sprintf('%s save took %.3f s', $role, $seconds));
        }
    }
}
