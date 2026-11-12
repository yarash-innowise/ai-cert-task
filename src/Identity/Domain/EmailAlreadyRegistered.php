<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class EmailAlreadyRegistered extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This email is already registered.', 0, $previous);
    }
}
