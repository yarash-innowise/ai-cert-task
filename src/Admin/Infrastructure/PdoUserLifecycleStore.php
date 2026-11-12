<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\AnonymizationResult;
use App\Admin\Domain\UserLifecycleStore;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Shared\Domain\Email;
use DateTimeImmutable;
use PDO;

/** PostgreSQL implementation of the user lifecycle changes; every statement is prepared and bound. */
final class PdoUserLifecycleStore implements UserLifecycleStore
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly PDO $pdo) {}

    public function lock(string $userId): ?User
    {
        if (preg_match(self::UUID, $userId) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function lockActiveSuperAdmins(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active' ORDER BY id FOR UPDATE");
        $stmt->execute();

        return count($stmt->fetchAll());
    }

    public function setStatus(string $userId, UserStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :s, version = version + 1, updated_at = now() WHERE id = :id');
        $stmt->execute(['s' => $status->value, 'id' => $userId]);
    }

    /** @return list<string> */
    public function moveChildLogins(string $guardianUserId, UserStatus $from, UserStatus $to): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET status = :to, version = version + 1, updated_at = now()
              WHERE guardian_user_id = :g AND account_kind = 'child' AND status = :from
          RETURNING id",
        );
        $stmt->execute(['to' => $to->value, 'g' => $guardianUserId, 'from' => $from->value]);

        return array_values(array_map(static fn(mixed $id): string => (string) $id, $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function guardianStatusOf(string $childUserId): ?UserStatus
    {
        $stmt = $this->pdo->prepare(
            "SELECT g.status FROM users c JOIN users g ON g.id = c.guardian_user_id WHERE c.id = :id AND c.account_kind = 'child'",
        );
        $stmt->execute(['id' => $childUserId]);
        $status = $stmt->fetchColumn();

        return is_string($status) ? UserStatus::from($status) : null;
    }

    public function setOrganizationStatus(string $trainerUserId, string $status): ?string
    {
        $stmt = $this->pdo->prepare('UPDATE organizations SET status = :s, updated_at = now() WHERE owner_user_id = :u RETURNING id');
        $stmt->execute(['s' => $status, 'u' => $trainerUserId]);
        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : null;
    }

    public function lockOrganizationOf(string $trainerUserId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM organizations WHERE owner_user_id = :u FOR UPDATE');
        $stmt->execute(['u' => $trainerUserId]);
        $stmt->fetchAll();
    }

    public function endCoachMemberships(string $trainerUserId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE coach_memberships SET status = 'ended', ended_at = now()
              WHERE status = 'active' AND organization_id IN (SELECT id FROM organizations WHERE owner_user_id = :u)",
        );
        $stmt->execute(['u' => $trainerUserId]);

        return $stmt->rowCount();
    }

    public function activeCoachCount(string $trainerUserId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT count(*)
               FROM organizations o
               JOIN coach_memberships m ON m.organization_id = o.id AND m.status = 'active'
               JOIN users c ON c.id = m.coach_user_id AND c.status = 'active'
              WHERE o.owner_user_id = :u",
        );
        $stmt->execute(['u' => $trainerUserId]);

        return (int) $stmt->fetchColumn();
    }

    public function anonymize(string $userId): AnonymizationResult
    {
        // The user and, for a guardian, the child logins that go with them (GDPR, Q-01.14).
        $accounts = [$userId, ...$this->column(
            "SELECT id FROM users WHERE guardian_user_id = :u AND account_kind = 'child' AND id <> :u",
            ['u' => $userId],
        )];
        $a = $this->pgArray($accounts);
        $profileIds = $this->column(
            'SELECT id FROM player_profiles WHERE login_user_id = ANY(:a::uuid[]) OR owner_user_id = ANY(:a::uuid[])',
            ['a' => $a],
        );
        $p = $this->pgArray($profileIds);

        // What names the people, read before it is overwritten: original emails (lower case) and display names.
        $emails = $this->pgTextArray($this->column(
            "SELECT lower(email) FROM users WHERE id = ANY(:a::uuid[]) AND email NOT LIKE 'deleted\\_%@example.com'",
            ['a' => $a],
        ));
        $names = $this->pgTextArray($this->column(
            "SELECT n FROM (SELECT name AS n FROM users WHERE id = ANY(:a::uuid[])
                            UNION SELECT trim(first_name || ' ' || last_name) FROM player_profiles WHERE id = ANY(:p::uuid[])) x
              WHERE n <> '' AND n <> 'Deleted User'",
            ['a' => $a, 'p' => $p],
        ));

        // Files first: once the paths are cleared nothing points at them any more.
        $photos = $this->column('SELECT photo_path FROM user_profiles WHERE user_id = ANY(:a::uuid[]) AND photo_path IS NOT NULL', ['a' => $a]);
        $logos = $this->column('SELECT logo_path FROM organizations WHERE owner_user_id = ANY(:a::uuid[]) AND logo_path IS NOT NULL', ['a' => $a]);
        if ($profileIds !== []) {
            $photos = [...$photos, ...$this->column(
                'SELECT photo_path FROM player_profiles WHERE id = ANY(:ids::uuid[]) AND photo_path IS NOT NULL',
                ['ids' => $p],
            )];
        }

        $this->run(
            "UPDATE users
                SET name = 'Deleted User', email = 'deleted_' || id::text || '@example.com', phone = NULL, password_hash = NULL,
                    must_set_password = false, email_verified_at = NULL, status = 'deleted', version = version + 1, updated_at = now()
              WHERE id = ANY(:a::uuid[])",
            ['a' => $a],
        );
        $this->run('UPDATE user_profiles SET photo_path = NULL, emergency_contact = NULL, admin_settings = NULL, updated_at = now() WHERE user_id = ANY(:a::uuid[])', ['a' => $a]);
        $this->run("UPDATE coach_profiles SET bio = NULL, credentials = NULL, certifications = '[]'::jsonb, is_public = false, updated_at = now() WHERE user_id = ANY(:a::uuid[])", ['a' => $a]);
        $this->run('UPDATE organizations SET logo_path = NULL, status = :s, updated_at = now() WHERE owner_user_id = ANY(:a::uuid[])', ['a' => $a, 's' => 'inactive']);

        if ($profileIds !== []) {
            $this->run(
                "UPDATE player_profiles
                    SET first_name = 'Deleted', last_name = 'User', birth_date = NULL, school = NULL, jersey_number = NULL, photo_path = NULL
                  WHERE id = ANY(:ids::uuid[])",
                ['ids' => $p],
            );
        }
        $this->run('DELETE FROM sessions WHERE user_id = ANY(:a::uuid[])', ['a' => $a]);
        $this->run('DELETE FROM auth_tokens WHERE user_id = ANY(:a::uuid[])', ['a' => $a]);
        $this->scrubTraces($a, $p, $emails, $names);

        return new AnonymizationResult($photos, $logos, $profileIds);
    }

    /**
     * Removes the personal data other tables hold about the anonymized accounts. Rows stay wherever they are
     * history (approvals, assignments, overrides, audit) so counts do not change; only text that names the
     * person goes. Audit rows hold ids only and are not touched.
     */
    private function scrubTraces(string $accounts, string $profiles, string $emails, string $names): void
    {
        $in = ['a' => $accounts];
        $this->run('DELETE FROM login_attempts WHERE lower(email) = ANY(:e::text[])', ['e' => $emails]);
        $this->run('DELETE FROM registration_prefills WHERE lower(data->>\'email\') = ANY(:e::text[])', ['e' => $emails]);

        // Invitations: the invitee address, name and message; the message the deleted user wrote as an inviter.
        $this->run(
            "UPDATE coach_invitations SET email = 'deleted_' || id::text || '@example.com', name = NULL, message = NULL
              WHERE lower(email) = ANY(:e::text[]) OR accepted_by = ANY(:a::uuid[])",
            ['e' => $emails, ...$in],
        );
        $this->run('UPDATE coach_invitations SET message = NULL WHERE created_by = ANY(:a::uuid[]) AND message IS NOT NULL', $in);

        // Queued emails (never sent) to them or naming them; sent ones are already scrubbed by the outbox worker.
        $this->run(
            "DELETE FROM outbox
              WHERE kind = 'email' AND status IN ('pending', 'processing', 'failed')
                AND (lower(payload->>'to') = ANY(:e::text[])
                     OR EXISTS (SELECT 1 FROM jsonb_each_text(CASE WHEN jsonb_typeof(payload->'context') = 'object' THEN payload->'context' ELSE '{}'::jsonb END) c
                                 WHERE c.value = ANY(:n::text[])))",
            ['e' => $emails, 'n' => $names],
        );

        // Notifications: their own go; what others were told about them (title and body carry names) is blanked,
        // the ids in the payload stay so the row still points at its history.
        $this->run('DELETE FROM notifications WHERE user_id = ANY(:a::uuid[])', $in);
        $this->run(
            "UPDATE notifications SET title = 'Notification about a deleted user', body = ''
              WHERE user_id <> ALL(:a::uuid[])
                AND (payload->>'profile_id' = ANY(:p::text[])
                     OR payload->>'coach_user_id' = ANY(:a::text[])
                     OR payload->>'request_id' IN (SELECT id::text FROM approval_requests WHERE child_user_id = ANY(:a::uuid[]) OR parent_user_id = ANY(:a::uuid[]))
                     OR payload->>'assignment_id' IN (SELECT id::text FROM coach_assignments WHERE coach_user_id = ANY(:a::uuid[])))",
            [...$in, 'p' => $profiles],
        );

        // Free text the person wrote; the rows stay.
        $this->run('UPDATE approval_requests SET child_note = NULL, parent_note = NULL WHERE child_user_id = ANY(:a::uuid[]) OR parent_user_id = ANY(:a::uuid[])', $in);
        $this->run('UPDATE coach_assignments SET change_note = NULL WHERE coach_user_id = ANY(:a::uuid[]) AND change_note IS NOT NULL', $in);
        $this->run("UPDATE coach_availability_overrides SET reason = '[removed]' WHERE overridden_by = ANY(:a::uuid[]) OR coach_user_id = ANY(:a::uuid[])", $in);
    }

    /** @param array<string, scalar|null> $params */
    private function run(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map(static fn(mixed $value): string => (string) $value, $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<string> $values */
    private function pgTextArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"', $values)) . '}';
    }

    /** @param list<string> $values */
    private function pgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (string) $row['id'],
            Email::fromString((string) $row['email']),
            is_string($row['password_hash']) ? $row['password_hash'] : null,
            (string) $row['name'],
            Role::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            (string) $row['account_kind'],
            (bool) $row['must_set_password'],
            is_string($row['email_verified_at']) ? new DateTimeImmutable($row['email_verified_at']) : null,
            (int) $row['version'],
            is_string($row['phone']) ? $row['phone'] : null,
            is_string($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
        );
    }
}
