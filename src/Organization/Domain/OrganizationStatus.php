<?php

declare(strict_types=1);

namespace App\Organization\Domain;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
