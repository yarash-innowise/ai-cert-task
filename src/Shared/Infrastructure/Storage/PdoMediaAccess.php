<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Domain\MediaAccess;
use App\Shared\Domain\StoredMedia;
use App\Shared\Domain\Uuid;
use PDO;

/** Media authorization over the relationships stored in the database (organizations, memberships, associations). */
final class PdoMediaAccess implements MediaAccess
{
    public function __construct(private readonly PDO $pdo) {}

    public function locate(string $viewerId, bool $viewerIsAdmin, string $kind, string $id): ?StoredMedia
    {
        if (Uuid::tryFromString($id) === null || Uuid::tryFromString($viewerId) === null) {
            return null;
        }

        return match ($kind) {
            'logo' => $this->logo($viewerId, $id),
            'user-photo' => $this->userPhoto($viewerId, $viewerIsAdmin, $id, false),
            'user-thumb' => $this->userPhoto($viewerId, $viewerIsAdmin, $id, true),
            'profile-photo' => $this->profilePhoto($viewerId, $viewerIsAdmin, $id, false),
            'profile-thumb' => $this->profilePhoto($viewerId, $viewerIsAdmin, $id, true),
            default => null,
        };
    }

    private function logo(string $viewerId, string $organizationId): ?StoredMedia
    {
        if (!in_array($organizationId, $this->memberOrganizations($viewerId), true)) {
            return null;
        }
        $name = $this->scalar('SELECT logo_path FROM organizations WHERE id = :id', ['id' => $organizationId]);

        return $name === null ? null : new StoredMedia(FileStorage::LOGOS, $name);
    }

    private function profilePhoto(string $viewerId, bool $admin, string $profileId, bool $thumb): ?StoredMedia
    {
        $stmt = $this->pdo->prepare('SELECT owner_user_id, login_user_id, photo_path FROM player_profiles WHERE id = :id AND deleted_at IS NULL AND photo_path IS NOT NULL');
        $stmt->execute(['id' => $profileId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $allowed = $admin || $row['owner_user_id'] === $viewerId || $row['login_user_id'] === $viewerId
            || array_intersect($this->staffOrganizations($viewerId), $this->profileOrganizations($profileId)) !== [];

        return $allowed ? $this->photo((string) $row['photo_path'], $thumb) : null;
    }

    private function userPhoto(string $viewerId, bool $admin, string $userId, bool $thumb): ?StoredMedia
    {
        $name = $this->scalar('SELECT photo_path FROM user_profiles WHERE user_id = :id AND photo_path IS NOT NULL', ['id' => $userId]);
        if ($name === null) {
            return null;
        }
        $allowed = $admin || $userId === $viewerId || $this->isGuardianOf($viewerId, $userId) || $this->staffSees($viewerId, $userId)
            || $this->membersSeeStaff($viewerId, $userId);

        return $allowed ? $this->photo($name, $thumb) : null;
    }

    private function photo(string $name, bool $thumb): StoredMedia
    {
        return new StoredMedia(FileStorage::PHOTOS, $thumb ? ImageStore::thumbName($name) : $name);
    }

    private function isGuardianOf(string $viewerId, string $userId): bool
    {
        return $this->scalar('SELECT id FROM users WHERE id = :child AND guardian_user_id = :guardian', ['child' => $userId, 'guardian' => $viewerId]) !== null;
    }

    /**
     * The people of an organization see its Trainer's photo, and a Coach's photo when the Coach made the profile
     * public (the Trainer and fellow staff always see it).
     */
    private function membersSeeStaff(string $viewerId, string $userId): bool
    {
        $member = $this->memberOrganizations($viewerId);
        if ($member === []) {
            return false;
        }
        $staff = $this->staffOrganizations($viewerId);
        $stmt = $this->pdo->prepare(
            "SELECT o.id AS organization_id, true AS is_trainer FROM organizations o WHERE o.owner_user_id = :trainer
             UNION ALL
             SELECT m.organization_id, false FROM coach_memberships m
               LEFT JOIN coach_profiles c ON c.user_id = m.coach_user_id
              WHERE m.coach_user_id = :coach AND m.status = 'active' AND (c.is_public IS TRUE OR m.organization_id = ANY(:staff::uuid[]))",
        );
        $stmt->execute(['trainer' => $userId, 'coach' => $userId, 'staff' => '{' . implode(',', $staff) . '}']);
        foreach ($stmt->fetchAll() as $row) {
            if (in_array((string) $row['organization_id'], $member, true)) {
                return true;
            }
        }

        return false;
    }

    /** A Trainer or Coach sees the photos of the account holders (and their child logins) associated with their organization. */
    private function staffSees(string $viewerId, string $userId): bool
    {
        $staff = $this->staffOrganizations($viewerId);
        if ($staff === []) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.organization_id FROM player_trainer_associations a
             JOIN player_profiles p ON p.id = a.player_profile_id AND p.deleted_at IS NULL
             WHERE a.ended_at IS NULL AND (p.owner_user_id = :owner OR p.login_user_id = :login)',
        );
        $stmt->execute(['owner' => $userId, 'login' => $userId]);

        return array_intersect($staff, array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))) !== [];
    }

    /** @return list<string> organizations whose Trainer or Coach the viewer is */
    private function staffOrganizations(string $viewerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM organizations WHERE owner_user_id = :owner
             UNION SELECT organization_id FROM coach_memberships WHERE coach_user_id = :coach AND status = 'active'",
        );
        $stmt->execute(['owner' => $viewerId, 'coach' => $viewerId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> organizations the viewer belongs to: as Trainer, Coach, or through an active association of their profiles */
    private function memberOrganizations(string $viewerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.organization_id FROM player_trainer_associations a
             JOIN player_profiles p ON p.id = a.player_profile_id AND p.deleted_at IS NULL
             WHERE a.ended_at IS NULL AND (p.owner_user_id = :owner OR p.login_user_id = :login)',
        );
        $stmt->execute(['owner' => $viewerId, 'login' => $viewerId]);

        return array_values(array_unique([...$this->staffOrganizations($viewerId), ...array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))]));
    }

    /** @return list<string> */
    private function profileOrganizations(string $profileId): array
    {
        $stmt = $this->pdo->prepare('SELECT organization_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL');
        $stmt->execute(['p' => $profileId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string, string> $params */
    private function scalar(string $sql, array $params): ?string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
