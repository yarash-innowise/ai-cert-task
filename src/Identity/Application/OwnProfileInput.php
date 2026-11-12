<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Domain\Phone;

/** Validated input of the own-profile form: the common fields plus one DTO per role section shown. */
final readonly class OwnProfileInput
{
    /** @param list<RoleProfileFields> $fields */
    public function __construct(
        public string $name,
        public ?Phone $phone,
        public array $fields,
    ) {}
}
