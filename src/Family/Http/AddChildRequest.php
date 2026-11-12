<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\AddChildInput;
use App\Family\Domain\Gender;
use App\Shared\Http\Exception\ValidationFailed;
use Psr\Http\Message\UploadedFileInterface;

/** Boundary validator of the add-child form; every message names its field. */
final class AddChildRequest
{
    public const TEMPLATE = 'family/children/new.twig';

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body, ?UploadedFileInterface $photo = null): AddChildInput
    {
        $first = self::text($body, 'first_name');
        $last = self::text($body, 'last_name');
        $birth = self::text($body, 'birth_date');
        $genderRaw = self::text($body, 'gender');
        $school = self::text($body, 'school');
        $old = ['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth, 'gender' => $genderRaw, 'school' => $school];

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
        $birthDate = BirthDate::parse($birth);
        if ($birthDate === null) {
            $errors['birth_date'] = $birth === '' ? 'Birth date is required.' : 'Enter the birth date as YYYY-MM-DD.';
        }
        $gender = Gender::tryFrom($genderRaw);
        if ($gender === null) {
            $errors['gender'] = 'Choose a gender.';
        }
        if (mb_strlen($school) > 200) {
            $errors['school'] = 'School must be 200 characters or fewer.';
        }

        if ($errors !== [] || $birthDate === null || $gender === null) {
            throw (new ValidationFailed($errors))->forForm(self::TEMPLATE, $old);
        }

        return new AddChildInput($first, $last, $birthDate, $gender, $school === '' ? null : $school, ($body['confirm_similar'] ?? '') === '1', $photo);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
