<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\LoginReservation;
use App\Identity\Domain\LoginThrottle;
use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * Five failures per email OR per IP inside 15 minutes lock sign-in until the fifth most recent
 * failure is 15 minutes old (Q-01.10, design D6). Check and record are one atomic step per email and per IP (advisory transaction
 * locks), and an allowed attempt is recorded up front as a failure that a correct password then settles. Attempts refused while locked are not recorded,
 * so the lock cannot be extended indefinitely by the person being locked out.
 */
final class PdoLoginThrottle implements LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 'PT15M';
    /** Advisory lock namespace: pg_advisory_xact_lock(hashtext('login:email:<email>')) and 'login:ip:<ip>'. */
    public const LOCK_PREFIX = 'login:';
    private const TS = 'Y-m-d H:i:s.uP';

    public function __construct(private readonly PDO $pdo) {}

    public function reserveAttempt(string $email, string $ip, DateTimeImmutable $now): LoginReservation
    {
        $email = mb_substr($email, 0, 254);
        $ip = mb_substr($ip, 0, 64);

        return $this->atomically(function () use ($email, $ip, $now): LoginReservation {
            // Both locks, always in the same order (no deadlock between two attempts), held until commit.
            $keys = [self::LOCK_PREFIX . 'email:' . $email, self::LOCK_PREFIX . 'ip:' . $ip];
            sort($keys);
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:k))');
            foreach ($keys as $key) {
                $lock->execute(['k' => $key]);
            }

            $byEmail = $this->lockEnd('email', $email, $now);
            $byIp = $this->lockEnd('ip', $ip, $now);
            if ($byEmail !== null || $byIp !== null) {
                return LoginReservation::locked($byEmail === null || $byIp === null ? ($byEmail ?? $byIp) : max($byEmail, $byIp));
            }

            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (:e, :ip, false, :at) RETURNING id');
            $stmt->execute(['e' => $email, 'ip' => $ip, 'at' => $now->format(self::TS)]);

            return LoginReservation::granted((int) $stmt->fetchColumn());
        });
    }

    public function recordSuccess(LoginReservation $reservation): void
    {
        if ($reservation->attemptId === null) {
            return;
        }
        $this->pdo->prepare('UPDATE login_attempts SET succeeded = true WHERE id = :id')->execute(['id' => $reservation->attemptId]);
    }

    public function release(LoginReservation $reservation): void
    {
        if ($reservation->attemptId === null) {
            return;
        }
        $this->pdo->prepare('DELETE FROM login_attempts WHERE id = :id')->execute(['id' => $reservation->attemptId]);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        // Inside a caller's transaction the advisory locks simply live until that transaction ends.
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->rollBackIfOpen();
            throw $e;
        }

        return $result;
    }

    private function rollBackIfOpen(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function lockEnd(string $column, string $value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $window = new DateInterval(self::WINDOW);
        // $column is one of two literals chosen by this class, never input.
        $stmt = $this->pdo->prepare(
            "SELECT attempted_at FROM login_attempts
             WHERE {$column} = :v AND succeeded = false AND attempted_at > :since
             ORDER BY attempted_at DESC LIMIT " . self::MAX_FAILURES,
        );
        $stmt->execute([
            'v' => $column === 'email' ? mb_substr($value, 0, 254) : mb_substr($value, 0, 64),
            'since' => $now->sub($window)->format(self::TS),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < self::MAX_FAILURES) {
            return null;
        }

        return (new DateTimeImmutable((string) $rows[self::MAX_FAILURES - 1]))->add($window);
    }
}
