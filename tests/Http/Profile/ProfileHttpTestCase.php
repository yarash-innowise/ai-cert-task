<?php

declare(strict_types=1);

namespace Tests\Http\Profile;

use Psr\Http\Message\ResponseInterface;
use Tests\Http\Branding\BrandingHttpTestCase;
use Tests\Support\ImageFixtures;

/** Fixtures for the profile-editing tests. */
abstract class ProfileHttpTestCase extends BrandingHttpTestCase
{
    /**
     * @param array<string, string> $fields
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    protected function saveProfile(array $fields = [], array $files = []): ResponseInterface
    {
        return $this->postFiles('/profile', ['name' => 'Alex Example', ...$fields], $files);
    }

    protected function photoUpload(?string $bytes = null, string $name = 'me.png'): \Nyholm\Psr7\UploadedFile
    {
        return ImageFixtures::file($bytes ?? ImageFixtures::png(300, 200), $name);
    }

    /** @return array<string, mixed> */
    protected function user(string $id): array
    {
        return $this->row('SELECT name, email, phone, role, status, version, account_kind FROM users WHERE id = :i', ['i' => $id]) ?? self::fail('missing user');
    }

    protected function photoPathOf(string $userId): ?string
    {
        $value = $this->scalar('SELECT photo_path FROM user_profiles WHERE user_id = :u', ['u' => $userId]);

        return is_string($value) ? $value : null;
    }

    /**
     * A signed-in player with a "Me" profile.
     *
     * @return array{string, string} user id and profile id
     */
    protected function signInAsPlayerWithSelfProfile(): array
    {
        $user = $this->signInAs('player');
        $profile = $this->selfOf($user);

        return [$user, $profile];
    }
}
