<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** 22-character base62 code, drawn from a CSPRNG (22 x log2(62) = 131 bits, at least 128). */
final class ShareLinkCode
{
    public const LENGTH = 22;
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, 61)];
        }

        return $code;
    }

    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^[0-9A-Za-z]{' . self::LENGTH . '}$/', $code) === 1;
    }
}
