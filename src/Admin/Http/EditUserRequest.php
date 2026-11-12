<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\EditUserInput;
use App\Shared\Domain\Email;
use App\Shared\Domain\Phone;
use App\Shared\Http\Exception\ValidationFailed;

/**
 * Boundary validator of the admin edit form. Only name, phone, email and the version are read:
 * role, status and everything else posted is ignored (never bound).
 */
final class EditUserRequest
{
    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body, string $template): EditUserInput
    {
        $name = self::text($body, 'name');
        $phoneRaw = self::text($body, 'phone');
        $emailRaw = self::text($body, 'email');
        $versionRaw = self::text($body, 'version');
        $old = ['name' => $name, 'phone' => $phoneRaw, 'email' => $emailRaw, 'version' => $versionRaw];

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'Name must be 200 characters or fewer.';
        }

        $phone = null;
        if ($phoneRaw !== '') {
            try {
                $phone = Phone::fromString($phoneRaw);
            } catch (ValidationFailed $e) {
                $errors['phone'] = $e->errors['phone'] ?? 'Enter a valid phone number.';
            }
        }

        $email = null;
        try {
            $email = Email::fromString($emailRaw);
        } catch (ValidationFailed $e) {
            $errors['email'] = $e->errors['email'] ?? 'Enter a valid email address.';
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/', $versionRaw) !== 1) {
            $errors['version'] = 'The form is out of date. Reload the page and try again.';
        }

        if ($errors !== [] || $email === null) {
            throw (new ValidationFailed($errors))->forForm($template, $old);
        }

        return new EditUserInput($name, $phone, $email, (int) $versionRaw);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
