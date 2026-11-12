<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\UserExtras;
use App\Identity\Domain\UserProfileRepository;
use PDO;

final class PdoUserProfileRepository implements UserProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(string $userId): UserExtras
    {
        $stmt = $this->pdo->prepare('SELECT photo_path, emergency_contact, admin_settings FROM user_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return new UserExtras();
        }

        return new UserExtras(
            is_string($row['photo_path']) ? $row['photo_path'] : null,
            is_string($row['emergency_contact']) ? $row['emergency_contact'] : null,
            is_string($row['admin_settings']) ? $row['admin_settings'] : null,
        );
    }

    public function saveText(string $userId, ?string $emergencyContact, ?string $adminSettings): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, emergency_contact, admin_settings) VALUES (:id, :emergency, :admin)
             ON CONFLICT (user_id) DO UPDATE SET emergency_contact = EXCLUDED.emergency_contact, admin_settings = EXCLUDED.admin_settings, updated_at = now()',
        );
        $stmt->execute(['id' => $userId, 'emergency' => $emergencyContact, 'admin' => $adminSettings]);
    }

    public function swapPhoto(string $userId, ?string $photoPath): ?string
    {
        $this->pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:id) ON CONFLICT (user_id) DO NOTHING')->execute(['id' => $userId]);
        $select = $this->pdo->prepare('SELECT photo_path FROM user_profiles WHERE user_id = :id FOR UPDATE');
        $select->execute(['id' => $userId]);
        $previous = $select->fetchColumn();
        $this->pdo->prepare('UPDATE user_profiles SET photo_path = :path, updated_at = now() WHERE user_id = :id')->execute(['path' => $photoPath, 'id' => $userId]);

        return is_string($previous) && $previous !== '' ? $previous : null;
    }
}
