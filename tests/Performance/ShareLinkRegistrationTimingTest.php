<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Admin\Infrastructure\UserSeeder;
use App\Identity\Domain\PasswordHasher;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Onboarding\OnboardingHttpTestCase;
use Tests\Support\PerfLog;

/** ShareLink registration completes in under 2 seconds with the production Argon2id cost, on 10,000 users (AC-88). Run with `--group perf`. */
#[Group('perf'), Group('AC-88')]
final class ShareLinkRegistrationTimingTest extends OnboardingHttpTestCase
{
    public function testRegistrationCompletesInUnderTwoSecondsWithProductionHashing(): void
    {
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        (new UserSeeder($this->pdo))->seed(10000);
        $this->pdo->exec('ANALYZE');
        \assert($this->container instanceof \DI\Container);
        $this->container->set(PasswordHasher::class, new PasswordHasher());
        $this->cookies = [];

        $worst = 0.0;
        for ($i = 1; $i <= 5; $i++) {
            $started = hrtime(true);
            $response = $this->post('/join/' . $code . '/register', [
                'name' => 'Pat Parent', 'email' => "pat{$i}@example.com", 'password' => 'a long enough passphrase', 'phone' => '+1 415 555 2671',
                'player_for' => 'child', 'player_name' => 'Mia Parent', 'birth_date' => '2022-05-01', 'gender' => 'female',
            ]);
            $seconds = (hrtime(true) - $started) / 1e9;
            PerfLog::record('sharelink registration #' . $i, $seconds, 'production Argon2id, 10,000 users');
            $worst = max($worst, $seconds);

            self::assertSame(200, $response->getStatusCode());
        }
        self::assertLessThan(2.0, $worst, sprintf('Registration took %.3f s', $worst));
    }
}
