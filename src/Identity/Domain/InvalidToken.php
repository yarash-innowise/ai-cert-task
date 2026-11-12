<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/** The one-time link is unknown, used, revoked or expired. */
final class InvalidToken extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This link has expired or has already been used.');
    }
}
