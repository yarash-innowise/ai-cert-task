<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Identity\Infrastructure\PdoLoginThrottle;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

/**
 * The throttle check and the failure record are one step guarded by advisory locks per email and
 * per IP, so concurrent attempts are serialised instead of all passing the check (review F-07).
 */
#[Group('AC-3')]
final class LoginThrottleAtomicityTest extends IntegrationTestCase
{
    public function testReservingTakesTheEmailAndIpAdvisoryLocksUntilTheTransactionEnds(): void
    {
        $throttle = new PdoLoginThrottle($this->pdo);
        $reservation = $throttle->reserveAttempt('lock-me@example.com', '203.0.113.50', new \DateTimeImmutable());
        self::assertNotNull($reservation->attemptId);

        $other = self::connectAsAppRw();
        $other->beginTransaction();
        self::assertFalse($this->tryLock($other, 'login:email:lock-me@example.com'), 'a parallel attempt for the same email must wait');
        self::assertFalse($this->tryLock($other, 'login:ip:203.0.113.50'), 'a parallel attempt from the same IP must wait');
        self::assertTrue($this->tryLock($other, 'login:email:someone-else@example.com'), 'unrelated emails are not blocked');
        $other->rollBack();

        $this->pdo->rollBack();
        $afterCommit = self::connectAsAppRw();
        $afterCommit->beginTransaction();
        self::assertTrue($this->tryLock($afterCommit, 'login:email:lock-me@example.com'), 'released when the transaction ends');
        $afterCommit->rollBack();
    }

    public function testTheAttemptIsCountedBeforeThePasswordIsChecked(): void
    {
        $throttle = new PdoLoginThrottle($this->pdo);
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < PdoLoginThrottle::MAX_FAILURES; ++$i) {
            self::assertNull($throttle->reserveAttempt('burst@example.com', '203.0.113.51', $now)->lockedUntil);
        }

        self::assertNotNull($throttle->reserveAttempt('burst@example.com', '203.0.113.51', $now)->lockedUntil, 'the sixth is refused');
        self::assertSame(5, (int) $this->scalar("SELECT count(*) FROM login_attempts WHERE email = 'burst@example.com'"));
    }

    public function testSettlingASuccessOrReleasingFreesTheSlot(): void
    {
        $throttle = new PdoLoginThrottle($this->pdo);
        $now = new \DateTimeImmutable();

        $ok = $throttle->reserveAttempt('settle@example.com', '203.0.113.52', $now);
        $throttle->recordSuccess($ok);
        $blocked = $throttle->reserveAttempt('settle@example.com', '203.0.113.52', $now);
        $throttle->release($blocked);

        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM login_attempts WHERE email = 'settle@example.com' AND succeeded = true"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM login_attempts WHERE email = 'settle@example.com' AND succeeded = false"));
    }

    private function tryLock(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare('SELECT pg_try_advisory_xact_lock(hashtext(:k))');
        $stmt->execute(['k' => $key]);

        return (bool) $stmt->fetchColumn();
    }
}
