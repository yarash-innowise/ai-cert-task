<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Application\AdminFields;
use App\Identity\Application\CoachFields;
use App\Identity\Application\OwnProfileInput;
use App\Identity\Application\ParentFields;
use App\Identity\Application\PlayerFields;
use App\Identity\Application\ProfileSection;
use App\Identity\Application\RoleProfileFields;
use App\Identity\Application\TrainerFields;
use App\Shared\Domain\Phone;
use App\Shared\Http\Exception\ValidationFailed;

/**
 * Boundary validator of the own-profile form (AC-68, AC-70). Only the fields of the sections that apply to
 * the account are read; email, role, status, skill level and anything else a tampered form posts is never
 * bound. Every message names its field and the field ids match the form's.
 */
final class OwnProfileRequest
{
    private const MAX_CERTIFICATIONS = 20;

    /**
     * @param array<array-key, mixed> $body
     * @param list<ProfileSection> $sections
     * @throws ValidationFailed carrying the submitted values in `old`
     */
    public static function fromArray(array $body, array $sections): OwnProfileInput
    {
        $errors = [];
        $old = [];

        $name = self::text($body, 'name');
        $old['name'] = $name;
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'Name must be 200 characters or fewer.';
        }

        $phoneRaw = self::text($body, 'phone');
        $old['phone'] = $phoneRaw;
        $phone = null;
        if ($phoneRaw !== '') {
            try {
                $phone = Phone::fromString($phoneRaw);
            } catch (ValidationFailed $e) {
                $errors['phone'] = $e->errors['phone'] ?? 'Enter a valid phone number, for example +1 415 555 2671.';
            }
        }

        /** @var list<RoleProfileFields> $fields */
        $fields = [];
        foreach ($sections as $section) {
            $parsed = match ($section) {
                ProfileSection::Player => self::player($body, $errors, $old),
                ProfileSection::Parent => self::parent($body, $errors, $old),
                ProfileSection::Coach => self::coach($body, $errors, $old),
                ProfileSection::Trainer => self::trainer($body, $errors, $old),
                ProfileSection::Admin => self::admin($body, $errors, $old),
            };
            if ($parsed !== null) {
                $fields[] = $parsed;
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors, null, $old);
        }

        return new OwnProfileInput($name, $phone, $fields);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function player(array $body, array &$errors, array &$old): ?PlayerFields
    {
        $school = self::text($body, 'school');
        $jerseyRaw = self::text($body, 'jersey_number');
        $old['school'] = $school;
        $old['jersey_number'] = $jerseyRaw;
        $before = count($errors);

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

        return count($errors) > $before ? null : new PlayerFields($school === '' ? null : $school, $jersey);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function parent(array $body, array &$errors, array &$old): ?ParentFields
    {
        $contact = self::text($body, 'emergency_contact');
        $old['emergency_contact'] = $contact;
        if (mb_strlen($contact) > 500) {
            $errors['emergency_contact'] = 'Emergency contact must be 500 characters or fewer.';

            return null;
        }

        return new ParentFields($contact === '' ? null : $contact);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function coach(array $body, array &$errors, array &$old): ?CoachFields
    {
        $bio = self::text($body, 'bio');
        $credentials = self::text($body, 'credentials');
        $certificationsRaw = is_string($body['certifications'] ?? null) ? $body['certifications'] : '';
        $public = ($body['is_public'] ?? '') === '1';
        $old['bio'] = $bio;
        $old['credentials'] = $credentials;
        $old['certifications'] = $certificationsRaw;
        $old['is_public'] = $public;
        $before = count($errors);

        if (mb_strlen($bio) > 2000) {
            $errors['bio'] = 'Bio must be 2,000 characters or fewer.';
        }
        if (mb_strlen($credentials) > 1000) {
            $errors['credentials'] = 'Credentials must be 1,000 characters or fewer.';
        }
        $certifications = array_values(array_filter(array_map('trim', preg_split('/\R/', $certificationsRaw) ?: []), static fn(string $line): bool => $line !== ''));
        if (count($certifications) > self::MAX_CERTIFICATIONS) {
            $errors['certifications'] = 'List at most ' . self::MAX_CERTIFICATIONS . ' certifications, one per line.';
        } else {
            foreach ($certifications as $line) {
                if (mb_strlen($line) > 200) {
                    $errors['certifications'] = 'Each certification must be 200 characters or fewer.';
                    break;
                }
            }
        }

        return count($errors) > $before ? null : new CoachFields($bio === '' ? null : $bio, $credentials === '' ? null : $credentials, $certifications, $public);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function trainer(array $body, array &$errors, array &$old): ?TrainerFields
    {
        $business = self::text($body, 'business_name');
        $details = self::text($body, 'details');
        $old['business_name'] = $business;
        $old['details'] = $details;
        $before = count($errors);

        if ($business === '') {
            $errors['business_name'] = 'Business name is required.';
        } elseif (mb_strlen($business) > 200) {
            $errors['business_name'] = 'Business name must be 200 characters or fewer.';
        }
        if (mb_strlen($details) > 2000) {
            $errors['details'] = 'Organization details must be 2,000 characters or fewer.';
        }

        return count($errors) > $before ? null : new TrainerFields($business, $details === '' ? null : $details);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private static function admin(array $body, array &$errors, array &$old): ?AdminFields
    {
        $settings = self::text($body, 'admin_settings');
        $old['admin_settings'] = $settings;
        if (mb_strlen($settings) > 2000) {
            $errors['admin_settings'] = 'Admin settings must be 2,000 characters or fewer.';

            return null;
        }

        return new AdminFields($settings === '' ? null : $settings);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim($body[$key]) : '';
    }
}
