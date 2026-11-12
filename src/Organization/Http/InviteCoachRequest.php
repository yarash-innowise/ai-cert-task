<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Organization\Application\InviteCoachInput;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\ValidationFailed;

/** Boundary validator of the invitation form: the email is required, name and message are optional (AC-57). */
final class InviteCoachRequest
{
    public const NAME_MAX = 200;
    public const MESSAGE_MAX = 1000;

    /**
     * @param array<array-key, mixed> $body
     * @throws ValidationFailed carrying the submitted values in `old`
     */
    public static function fromArray(array $body): InviteCoachInput
    {
        $emailRaw = self::text($body, 'email');
        $name = self::text($body, 'name');
        $message = self::text($body, 'message');
        $old = ['email' => $emailRaw, 'name' => $name, 'message' => $message];

        $errors = [];
        $email = null;
        try {
            $email = Email::fromString($emailRaw);
        } catch (ValidationFailed $e) {
            $errors += $e->errors;
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('Name must be %d characters or fewer.', self::NAME_MAX);
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            $errors['message'] = sprintf('The message must be %d characters or fewer.', self::MESSAGE_MAX);
        }
        if ($errors !== [] || $email === null) {
            throw new ValidationFailed($errors, null, $old);
        }

        return new InviteCoachInput($email, $name === '' ? null : $name, $message === '' ? null : $message);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
