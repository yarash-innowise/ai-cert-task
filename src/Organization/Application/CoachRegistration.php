<?php

declare(strict_types=1);

namespace App\Organization\Application;

/** A new Coach's account details when accepting (the email is the invited one; the link proves it). */
final readonly class CoachRegistration
{
    public function __construct(public string $name, public string $password) {}
}
