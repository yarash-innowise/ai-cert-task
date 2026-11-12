<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Admin\Infrastructure\UserSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Availability\AvailabilityHttpTestCase;
use Tests\Support\PerfLog;

/** Role dashboards load in under 2 seconds with 10,000 users and a Trainer with 300 players (AC-88). Run with `--group perf`. */
#[Group('perf'), Group('AC-88')]
final class DashboardTimingTest extends AvailabilityHttpTestCase
{
    public function testEveryRoleDashboardLoadsInUnderTwoSeconds(): void
    {
        [$trainerId, $org] = $this->trainerSignedIn();
        $coach = $this->coachIn($org);
        $parent = $this->createUser('player', 'pat@example.com', name: 'Pat Parent');
        $this->associate($this->selfOf($parent), $org);
        $this->associate($this->childOf($parent), $org);
        $this->pdo->exec(
            "WITH p AS (
                INSERT INTO users (email, name, role, email_verified_at)
                SELECT 'roster-' || n || '@example.test', 'Roster ' || n, 'player', now() FROM generate_series(1, 300) n RETURNING id)
             INSERT INTO player_profiles (owner_user_id, kind, first_name, last_name, birth_date, gender)
             SELECT id, 'self', 'Roster', 'Player', DATE '1990-01-01', 'other' FROM p",
        );
        $this->pdo->prepare(
            "INSERT INTO player_trainer_associations (player_profile_id, organization_id, associated_at)
             SELECT pp.id, :o, now() FROM player_profiles pp JOIN users u ON u.id = pp.owner_user_id WHERE u.email LIKE 'roster-%'",
        )->execute(['o' => $org]);
        (new UserSeeder($this->pdo))->seed(10000);
        $this->pdo->exec('ANALYZE');

        $screens = [
            'trainer' => [fn() => $this->signInAsUser($trainerId), ['/trainer', '/trainer/players', '/trainer/availability']],
            'coach' => [fn() => $this->signInAsCoachUser($coach), ['/coach', '/coach/schedule']],
            'family' => [fn() => $this->signInAsUser($parent), ['/family', '/family/children', '/family/events']],
            'admin' => [fn() => $this->signInAs('super_admin'), ['/admin', '/admin/users']],
        ];
        foreach ($screens as $role => [$signIn, $paths]) {
            $signIn();
            foreach ($paths as $path) {
                $started = hrtime(true);
                $response = $this->get($path);
                $seconds = (hrtime(true) - $started) / 1e9;
                PerfLog::record($role . ' ' . $path, $seconds, '10,000 users, 300-player roster');

                self::assertSame(200, $response->getStatusCode(), $role . ' ' . $path);
                self::assertLessThan(2.0, $seconds, sprintf('%s %s took %.3f s', $role, $path, $seconds));
            }
        }
    }
}
