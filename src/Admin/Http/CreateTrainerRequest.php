<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\CreateTrainerInput;
use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;
use App\Shared\Http\Exception\ValidationFailed;

/** Boundary validator of the create-Trainer form: every field is required and every message names its field. */
final class CreateTrainerRequest
{
    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): CreateTrainerInput
    {
        $businessName = self::text($body, 'business_name');
        $trainerName = self::text($body, 'trainer_name');
        $old = [
            'business_name' => $businessName,
            'trainer_name' => $trainerName,
            'email' => self::text($body, 'email'),
            'phone' => self::text($body, 'phone'),
        ];

        $errors = [];
        if ($businessName === '') {
            $errors['business_name'] = 'Business name is required.';
        } elseif (mb_strlen($businessName) > 200) {
            $errors['business_name'] = 'Business name must be 200 characters or fewer.';
        }
        if ($trainerName === '') {
            $errors['trainer_name'] = 'Trainer name is required.';
        } elseif (mb_strlen($trainerName) > 200) {
            $errors['trainer_name'] = 'Trainer name must be 200 characters or fewer.';
        }

        $email = null;
        try {
            $email = Email::fromString($old['email']);
        } catch (ValidationFailed $e) {
            $errors['email'] = $e->errors['email'] ?? 'Enter a valid email address.';
        }
        $phone = null;
        try {
            $phone = Phone::fromString($old['phone']);
        } catch (ValidationFailed $e) {
            $errors['phone'] = $e->errors['phone'] ?? 'Enter a valid phone number.';
        }

        if ($errors !== [] || $email === null || $phone === null) {
            throw (new ValidationFailed($errors))->forForm('admin/users/new.twig', $old);
        }

        return new CreateTrainerInput($businessName, $trainerName, $email, $phone);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
