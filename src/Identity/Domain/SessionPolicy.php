<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateInterval;
use DateTimeImmutable;

/**
 * Session lifetime rules: idle 7 days (Q-01.07) and an absolute 30 days (design D3).
 */
final class SessionPolicy
{
    public const IDLE = 'P7D';
    public const ABSOLUTE = 'P30D';
    public const TOUCH_INTERVAL_SECONDS = 60;
    public const COOKIE_MAX_AGE = 2592000;

    public function isExpired(Session $session, DateTimeImmutable $now): bool
    {
        return $session->lastSeenAt->add(new DateInterval(self::IDLE)) <= $now
            || $session->createdAt->add(new DateInterval(self::ABSOLUTE)) <= $now;
    }

    public function shouldTouch(Session $session, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $session->lastSeenAt->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS;
    }
}
