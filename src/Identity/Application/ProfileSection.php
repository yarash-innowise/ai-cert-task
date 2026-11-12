<?php

declare(strict_types=1);

namespace App\Identity\Application;

/** A group of role-specific profile fields (AC-68). A Player/Parent account can have a Parent and a Player section. */
enum ProfileSection: string
{
    case Player = 'player';
    case Parent = 'parent';
    case Coach = 'coach';
    case Trainer = 'trainer';
    case Admin = 'admin';
}
