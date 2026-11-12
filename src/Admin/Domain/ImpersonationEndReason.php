<?php

declare(strict_types=1);

namespace App\Admin\Domain;

enum ImpersonationEndReason: string
{
    case Manual = 'manual';
    case Timeout = 'timeout';
    case BrowserClosed = 'browser_closed';
    case Logout = 'logout';
    case SessionExpired = 'session_expired';
    case TargetUnavailable = 'target_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Exited by the administrator',
            self::Timeout => 'Ended automatically after 1 hour',
            self::BrowserClosed => 'Browser closed',
            self::Logout => 'Administrator signed out',
            self::SessionExpired => 'Session expired',
            self::TargetUnavailable => 'User was deleted',
        };
    }

    /** Ended by the system rather than by the administrator. */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::Timeout, self::BrowserClosed, self::SessionExpired, self::TargetUnavailable], true);
    }
}
