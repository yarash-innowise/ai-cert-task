<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\CoachProfile;
use App\Identity\Domain\CoachProfileRepository;
use PDO;

final class PdoCoachProfileRepository implements CoachProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(string $userId): ?CoachProfile
    {
        $stmt = $this->pdo->prepare('SELECT user_id, bio, credentials, certifications, is_public FROM coach_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) $row['certifications'], true);
        $certifications = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];

        return new CoachProfile(
            (string) $row['user_id'],
            is_string($row['bio']) ? $row['bio'] : null,
            is_string($row['credentials']) ? $row['credentials'] : null,
            $certifications,
            (bool) $row['is_public'],
        );
    }

    public function save(CoachProfile $profile): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO coach_profiles (user_id, bio, credentials, certifications, is_public)
             VALUES (:id, :bio, :credentials, :certifications, :public)
             ON CONFLICT (user_id) DO UPDATE SET bio = EXCLUDED.bio, credentials = EXCLUDED.credentials,
                certifications = EXCLUDED.certifications, is_public = EXCLUDED.is_public, updated_at = now()',
        );
        $stmt->bindValue('id', $profile->userId);
        $stmt->bindValue('bio', $profile->bio);
        $stmt->bindValue('credentials', $profile->credentials);
        $stmt->bindValue('certifications', json_encode($profile->certifications, JSON_THROW_ON_ERROR));
        $stmt->bindValue('public', $profile->isPublic, PDO::PARAM_BOOL);
        $stmt->execute();
    }
}
