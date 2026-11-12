<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\Gender;
use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;

/** Validated input of the add-child form. */
final readonly class AddChildInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public DateTimeImmutable $birthDate,
        public Gender $gender,
        public ?string $school,
        public bool $confirmSimilar,
        public ?UploadedFileInterface $photo = null,
    ) {}
}
