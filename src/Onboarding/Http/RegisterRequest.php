<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Family\Domain\Gender;
use App\Family\Http\BirthDate;
use App\Onboarding\Application\RegisterInput;
use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;
use App\Shared\Http\Exception\ValidationFailed;

/** Boundary validator of the registration form; every message names its field. The password is never echoed. */
final class RegisterRequest
{
    /**
     * @param array<array-key, mixed> $body
     * @throws ValidationFailed carrying the submitted non-secret values in `old`
     */
    public static function fromArray(array $body): RegisterInput
    {
        $name = self::text($body, 'name');
        $emailRaw = self::text($body, 'email');
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $phoneRaw = self::text($body, 'phone');
        $playerFor = self::text($body, 'player_for');
        $playerName = self::text($body, 'player_name');
        $birth = self::text($body, 'birth_date');
        $genderRaw = self::text($body, 'gender');
        $old = ['name' => $name, 'email' => $emailRaw, 'phone' => $phoneRaw, 'player_for' => $playerFor, 'player_name' => $playerName, 'birth_date' => $birth, 'gender' => $genderRaw];

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Your name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'Name must be 200 characters or fewer.';
        }
        $email = null;
        try {
            $email = Email::fromString($emailRaw);
        } catch (ValidationFailed $e) {
            $errors += $e->errors;
        }
        $phone = null;
        try {
            $phone = Phone::fromString($phoneRaw);
        } catch (ValidationFailed $e) {
            $errors += $e->errors;
        }
        if ($password === '') {
            $errors['password'] = 'Choose a password.';
        }
        if (!in_array($playerFor, ['me', 'child'], true)) {
            $errors['player_for'] = 'Choose who will train.';
        }
        $isChild = $playerFor === 'child';
        if ($isChild && $playerName === '') {
            $errors['player_name'] = "The player's name is required.";
        } elseif (mb_strlen($playerName) > 200) {
            $errors['player_name'] = 'Name must be 200 characters or fewer.';
        }
        $birthDate = BirthDate::parse($birth);
        if ($birthDate === null) {
            $errors['birth_date'] = $birth === '' ? 'Birth date is required.' : 'Enter the birth date as YYYY-MM-DD.';
        }
        $gender = Gender::tryFrom($genderRaw);
        if ($gender === null) {
            $errors['gender'] = 'Choose a gender.';
        }

        if ($errors !== [] || $email === null || $phone === null || $birthDate === null || $gender === null) {
            throw new ValidationFailed($errors, null, $old);
        }

        return new RegisterInput($name, $email, $password, $phone, $isChild, $playerName === '' ? $name : $playerName, $birthDate, $gender);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
