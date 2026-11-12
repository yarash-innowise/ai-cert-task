<?php

declare(strict_types=1);

namespace App\Family\Infrastructure;

use App\Family\Domain\Gender;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\ProfileKind;
use App\Family\Domain\SelfProfileExists;
use App\Shared\Domain\Uuid;
use DateTimeImmutable;
use PDO;
use PDOException;

final class PdoPlayerProfileRepository implements PlayerProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(string $id): ?PlayerProfile
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }

        return $this->one('SELECT * FROM player_profiles WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findOwned(string $id, string $ownerUserId): ?PlayerProfile
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }

        return $this->one('SELECT * FROM player_profiles WHERE id = :id AND owner_user_id = :owner AND deleted_at IS NULL', ['id' => $id, 'owner' => $ownerUserId]);
    }

    public function findByLogin(string $loginUserId): ?PlayerProfile
    {
        if (Uuid::tryFromString($loginUserId) === null) {
            return null;
        }

        return $this->one('SELECT * FROM player_profiles WHERE login_user_id = :login AND deleted_at IS NULL', ['login' => $loginUserId]);
    }

    public function findSelf(string $ownerUserId): ?PlayerProfile
    {
        return $this->one("SELECT * FROM player_profiles WHERE owner_user_id = :owner AND kind = 'self' AND deleted_at IS NULL", ['owner' => $ownerUserId]);
    }

    public function childrenOf(string $ownerUserId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM player_profiles WHERE owner_user_id = :owner AND kind = 'child' AND deleted_at IS NULL ORDER BY created_at, id");
        $stmt->execute(['owner' => $ownerUserId]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function createChild(string $ownerUserId, string $firstName, string $lastName, DateTimeImmutable $birthDate, Gender $gender, ?string $school, ?string $photoPath = null): PlayerProfile
    {
        return $this->insert($ownerUserId, ProfileKind::Child, $firstName, $lastName, $birthDate, $gender, $school, $photoPath);
    }

    public function createSelf(string $ownerUserId, string $firstName, string $lastName, DateTimeImmutable $birthDate, ?Gender $gender): PlayerProfile
    {
        try {
            return $this->insert($ownerUserId, ProfileKind::Me, $firstName, $lastName, $birthDate, $gender, null);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new SelfProfileExists('The account already has a "Me" profile.', 0, $e);
            }
            throw $e;
        }
    }

    public function setTokenSpendWithoutApproval(string $profileId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE player_profiles SET token_spend_without_approval = :enabled WHERE id = :id');
        $stmt->bindValue('enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $profileId);
        $stmt->execute();
    }

    public function updateDetails(string $profileId, ?string $school, ?int $jerseyNumber): void
    {
        $stmt = $this->pdo->prepare('UPDATE player_profiles SET school = :school, jersey_number = :jersey WHERE id = :id AND deleted_at IS NULL');
        $stmt->bindValue('school', $school);
        $stmt->bindValue('jersey', $jerseyNumber, $jerseyNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('id', $profileId);
        $stmt->execute();
    }

    public function updateChild(string $profileId, string $firstName, string $lastName, ?string $school, ?int $jerseyNumber): void
    {
        $stmt = $this->pdo->prepare('UPDATE player_profiles SET first_name = :first, last_name = :last, school = :school, jersey_number = :jersey WHERE id = :id AND deleted_at IS NULL');
        $stmt->bindValue('first', $firstName);
        $stmt->bindValue('last', $lastName);
        $stmt->bindValue('school', $school);
        $stmt->bindValue('jersey', $jerseyNumber, $jerseyNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('id', $profileId);
        $stmt->execute();
    }

    public function swapPhoto(string $profileId, ?string $photoPath): ?string
    {
        $select = $this->pdo->prepare('SELECT photo_path FROM player_profiles WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
        $select->execute(['id' => $profileId]);
        $previous = $select->fetchColumn();
        $update = $this->pdo->prepare('UPDATE player_profiles SET photo_path = :path WHERE id = :id');
        $update->execute(['path' => $photoPath, 'id' => $profileId]);

        return is_string($previous) && $previous !== '' ? $previous : null;
    }

    public function attachLogin(string $profileId, string $loginUserId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE player_profiles SET login_user_id = :login WHERE id = :id AND login_user_id IS NULL');
        $stmt->execute(['login' => $loginUserId, 'id' => $profileId]);

        return $stmt->rowCount() === 1;
    }

    private function insert(string $ownerUserId, ProfileKind $kind, string $firstName, string $lastName, DateTimeImmutable $birthDate, ?Gender $gender, ?string $school, ?string $photoPath = null): PlayerProfile
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_profiles (owner_user_id, kind, first_name, last_name, birth_date, gender, school, photo_path)
             VALUES (:owner, :kind, :first, :last, :birth, :gender, :school, :photo) RETURNING *',
        );
        $stmt->execute([
            'owner' => $ownerUserId,
            'kind' => $kind->value,
            'first' => $firstName,
            'last' => $lastName,
            'birth' => $birthDate->format('Y-m-d'),
            'gender' => $gender?->value,
            'school' => $school,
            'photo' => $photoPath,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    /** @param array<string, scalar|null> $params */
    private function one(string $sql, array $params): ?PlayerProfile
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PlayerProfile
    {
        return new PlayerProfile(
            (string) $row['id'],
            (string) $row['owner_user_id'],
            ProfileKind::from((string) $row['kind']),
            is_string($row['login_user_id']) ? $row['login_user_id'] : null,
            (string) $row['first_name'],
            (string) $row['last_name'],
            is_string($row['birth_date']) ? new DateTimeImmutable($row['birth_date']) : null,
            is_string($row['gender']) ? Gender::from($row['gender']) : null,
            is_string($row['school']) ? $row['school'] : null,
            (bool) $row['token_spend_without_approval'],
            is_numeric($row['jersey_number'] ?? null) ? (int) $row['jersey_number'] : null,
            is_string($row['photo_path'] ?? null) ? $row['photo_path'] : null,
            is_string($row['skill_level'] ?? null) ? $row['skill_level'] : null,
        );
    }
}
