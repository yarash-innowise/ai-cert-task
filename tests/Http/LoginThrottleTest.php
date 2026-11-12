<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

#[Group('AC-3')]
final class LoginThrottleTest extends AppHttpTestCase
{
    private function attempt(string $email, string $password, string $ip = '203.0.113.9'): int
    {
        $this->cookies = [];
        $token = $this->csrfToken();

        return $this->send('POST', '/login', ['email' => $email, 'password' => $password, '_csrf' => $token], [], null, $ip)->getStatusCode();
    }

    private function failFiveTimes(string $email, string $ip = '203.0.113.9'): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->attempt($email, 'wrong password ' . $i, $ip));
        }
    }

    public function testCorrectPasswordIsRefusedWhileLockedAndAllowedAfterThePeriod(): void
    {
        $this->createUser('player', 'lock@example.com');
        $this->failFiveTimes('lock@example.com');

        self::assertSame(429, $this->attempt('lock@example.com', 'correct horse battery'), 'correct password refused while locked');

        $this->clock->advance('14 minutes 50 seconds');
        self::assertSame(429, $this->attempt('lock@example.com', 'correct horse battery'));

        $this->clock->advance('20 seconds');
        self::assertSame(302, $this->attempt('lock@example.com', 'correct horse battery'), 'allowed again after 15 minutes');
    }

    public function testLockedMessageSaysWhenToTryAgainButNotWhyTheEmailMatters(): void
    {
        $this->createUser('player', 'lock@example.com');
        $this->failFiveTimes('lock@example.com');
        $known = $this->attempt('lock@example.com', 'x');

        $this->cookies = [];
        $token = $this->csrfToken();
        $body = $this->body($this->send('POST', '/login', ['email' => 'lock@example.com', 'password' => 'x', '_csrf' => $token]));
        self::assertSame(429, $known);
        self::assertMatchesRegularExpression('/Try again in \d+ minutes?\./', $body);
    }

    public function testRefusedAttemptsDoNotExtendTheLock(): void
    {
        $this->createUser('player', 'lock@example.com');
        $this->failFiveTimes('lock@example.com');

        $this->clock->advance('10 minutes');
        $this->attempt('lock@example.com', 'still wrong');
        $this->clock->advance('5 minutes 5 seconds');

        self::assertSame(302, $this->attempt('lock@example.com', 'correct horse battery'));
        self::assertSame(5, $this->scalar("SELECT count(*) FROM login_attempts WHERE succeeded = false"));
    }

    public function testAnUnknownEmailIsThrottledJustTheSame(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->attempt('ghost@example.com', 'whatever pass ' . $i));
        }

        self::assertSame(429, $this->attempt('ghost@example.com', 'whatever pass'));
    }

    public function testSameIpIsLockedAcrossDifferentEmails(): void
    {
        $this->createUser('player', 'victim@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $this->attempt("spray{$i}@example.com", 'guess number ' . $i, '198.51.100.20');
        }

        self::assertSame(429, $this->attempt('victim@example.com', 'correct horse battery', '198.51.100.20'));
        self::assertSame(302, $this->attempt('victim@example.com', 'correct horse battery', '198.51.100.21'), 'another address is unaffected');
    }

    public function testSameEmailIsLockedAcrossDifferentIps(): void
    {
        $this->createUser('player', 'target@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $this->attempt('target@example.com', 'guess number ' . $i, "198.51.100.{$i}");
        }

        self::assertSame(429, $this->attempt('target@example.com', 'correct horse battery', '192.0.2.77'));
    }

    public function testFourFailuresDoNotLock(): void
    {
        $this->createUser('player', 'four@example.com');
        for ($i = 0; $i < 4; ++$i) {
            $this->attempt('four@example.com', 'wrong ' . $i);
        }

        self::assertSame(302, $this->attempt('four@example.com', 'correct horse battery'));
    }

    public function testPurgeTaskKeepsRecentAttemptsOnly(): void
    {
        $this->attempt('a@example.com', 'x');
        $this->clock->advance('2 days');
        $this->attempt('b@example.com', 'x');

        $task = $this->container->get(\App\Identity\Infrastructure\PurgeLoginAttemptsTask::class);
        \assert($task instanceof \App\Identity\Infrastructure\PurgeLoginAttemptsTask);
        self::assertSame(1, $task->purge());
        self::assertSame(1, $this->scalar('SELECT count(*) FROM login_attempts'));
    }
}
