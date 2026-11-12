<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Http\Exception\ValidationFailed;

/**
 * 10-128 characters and not a commonly used password; no composition rules (design C4).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    private const COMMON = [
        'password12', 'password123', 'password1234', 'password12345', 'password123456', 'passw0rd123',
        '1234567890', '12345678910', '123456789012', '0123456789', '1111111111', '0000000000', 'aaaaaaaaaa',
        'qwertyuiop', 'qwerty1234', 'qwerty12345', 'qwertyuiop123', 'asdfghjkl1', 'asdfghjklqwe', 'zxcvbnm123',
        '1q2w3e4r5t', '1qaz2wsx3edc', 'iloveyou12', 'iloveyou123', 'letmein123', 'welcome123', 'welcome1234',
        'administrator', 'admin12345', 'admin123456', 'abc1234567', 'abcdefghij', 'abcd123456', 'changeme123',
        'trustno1234', 'sunshine123', 'princess123', 'football123', 'baseball123', 'basketball1', 'superman123',
        'monkey12345', 'dragon12345', 'master12345', 'shadow12345', 'michael1234', 'jennifer123', 'charlie1234',
        'passwordpassword', 'practiceperfect', 'practice123', 'trainer1234', 'coach12345', 'player12345',
    ];

    /** @throws ValidationFailed keyed by the `password` field */
    public function assertAcceptable(string $password, string $field = 'password'): void
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw ValidationFailed::forField($field, sprintf('Password must be %d to %d characters long.', self::MIN_LENGTH, self::MAX_LENGTH));
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            throw ValidationFailed::forField($field, 'This password is too common. Choose a different one.');
        }
    }
}
