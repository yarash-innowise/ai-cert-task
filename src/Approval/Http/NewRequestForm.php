<?php

declare(strict_types=1);

namespace App\Approval\Http;

use App\Approval\Application\NewRequestInput;
use App\Shared\Domain\Uuid;
use App\Shared\Http\Exception\ValidationFailed;

/** Boundary validator for the child's request forms: the event reference and an optional note. Title and amount are never read from the form (F-11). */
final class NewRequestForm
{
    public const NOTE_MAX = 500;

    /**
     * @param array<array-key, mixed> $body
     * @throws ValidationFailed
     */
    public static function fromArray(array $body): NewRequestInput
    {
        $errors = [];
        $event = self::text($body, 'event_ref');
        $uuid = Uuid::tryFromString($event);
        if ($uuid === null) {
            $errors['event_ref'] = 'Choose an event.';
        }
        $note = self::text($body, 'note');
        if (mb_strlen($note) > self::NOTE_MAX) {
            $errors['note'] = sprintf('The note must be %d characters or fewer.', self::NOTE_MAX);
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return new NewRequestInput($uuid->value, null, 0, 'USD', $note === '' ? null : $note);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
