<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\EditChildInput;
use App\Shared\Http\Exception\ValidationFailed;

/** Boundary validator of the edit-child form; every message names its field. Birth date, gender and the owner are never bound. */
final class EditChildRequest
{
    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): EditChildInput
    {
        $first = self::text($body, 'first_name');
        $last = self::text($body, 'last_name');
        $school = self::text($body, 'school');
        $jerseyRaw = self::text($body, 'jersey_number');

        $errors = [];
        if ($first === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif (mb_strlen($first) > 100) {
            $errors['first_name'] = 'First name must be 100 characters or fewer.';
        }
        if ($last === '') {
            $errors['last_name'] = 'Last name is required.';
        } elseif (mb_strlen($last) > 100) {
            $errors['last_name'] = 'Last name must be 100 characters or fewer.';
        }
        if (mb_strlen($school) > 200) {
            $errors['school'] = 'School must be 200 characters or fewer.';
        }
        $jersey = null;
        if ($jerseyRaw !== '') {
            if (preg_match('/^[0-9]{1,3}$/', $jerseyRaw) !== 1) {
                $errors['jersey_number'] = 'Jersey number must be a whole number from 0 to 999.';
            } else {
                $jersey = (int) $jerseyRaw;
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors, null, ['first_name' => $first, 'last_name' => $last, 'school' => $school, 'jersey_number' => $jerseyRaw]);
        }

        return new EditChildInput($first, $last, $school === '' ? null : $school, $jersey);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
