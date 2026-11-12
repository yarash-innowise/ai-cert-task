<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use Tests\Http\AppHttpTestCase;

/** Shared fixtures for the family (parent / child / Trainer) HTTP tests. The frozen clock is 2030-03-01. */
abstract class FamilyHttpTestCase extends AppHttpTestCase
{
    /** @return array{string, string} Trainer user id and organization id */
    protected function trainerWithOrganization(string $business = 'Ace Tennis Academy', string $status = 'active'): array
    {
        $trainer = $this->createUser('trainer', 'trainer' . bin2hex(random_bytes(3)) . '@example.com', name: 'Tina Trainer');
        $organization = (string) $this->scalar(
            'INSERT INTO organizations (owner_user_id, business_name, status) VALUES (:o, :n, :s) RETURNING id',
            ['o' => $trainer, 'n' => $business, 's' => $status],
        );

        return [$trainer, $organization];
    }

    protected function childOf(string $parentId, string $first = 'Mia', string $last = 'Smith', string $birth = '2022-05-01', string $gender = 'female'): string
    {
        return (string) $this->scalar(
            "INSERT INTO player_profiles (owner_user_id, kind, first_name, last_name, birth_date, gender) VALUES (:o, 'child', :f, :l, :b, :g) RETURNING id",
            ['o' => $parentId, 'f' => $first, 'l' => $last, 'b' => $birth, 'g' => $gender],
        );
    }

    protected function associate(string $profileId, string $organizationId, string $at = '2030-01-15 09:00:00+00'): void
    {
        $this->pdo->prepare('INSERT INTO player_trainer_associations (player_profile_id, organization_id, associated_at) VALUES (:p, :o, :a)')
            ->execute(['p' => $profileId, 'o' => $organizationId, 'a' => $at]);
    }

    protected function activeAssociations(string $profileId): int
    {
        return (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $profileId]);
    }

    protected function childCount(string $parentId): int
    {
        return (int) $this->scalar("SELECT count(*) FROM player_profiles WHERE owner_user_id = :o AND kind = 'child'", ['o' => $parentId]);
    }

    /** @param array<string, string> $overrides */
    protected function addChild(array $overrides = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/children/new', [
            'first_name' => 'Mia',
            'last_name' => 'Smith',
            'birth_date' => '2022-05-01',
            'gender' => 'female',
            ...$overrides,
        ]);
    }

    /** @return array{string, string} link id and code */
    protected function shareLink(string $organizationId, ?string $code = null, bool $active = true): array
    {
        $code ??= \App\Onboarding\Domain\ShareLinkCode::generate();
        $id = (string) $this->scalar(
            "INSERT INTO share_links (organization_id, kind, code, active, deactivated_at) VALUES (:o, 'player', :c, :a, :d) RETURNING id",
            ['o' => $organizationId, 'c' => $code, 'a' => $active ? 't' : 'f', 'd' => $active ? null : '2030-01-01 00:00:00+00'],
        );

        return [$id, $code];
    }

    protected function selfOf(string $ownerId, string $first = 'Pat', string $last = 'Parent', string $birth = '1985-06-01'): string
    {
        return (string) $this->scalar(
            "INSERT INTO player_profiles (owner_user_id, kind, first_name, last_name, birth_date, gender) VALUES (:o, 'self', :f, :l, :b, 'other') RETURNING id",
            ['o' => $ownerId, 'f' => $first, 'l' => $last, 'b' => $birth],
        );
    }

    /**
     * Gives a child profile its own login (as the parent's G1 action would) and signs in as it.
     *
     * @return string the child's user id
     */
    protected function signInAsChildLogin(string $parentId, string $profileId, string $email = 'kid@example.com'): string
    {
        $kid = (string) $this->scalar(
            "INSERT INTO users (email, name, role, account_kind, guardian_user_id, password_hash, email_verified_at)
             VALUES (:e, 'Kid Login', 'player', 'child', :g, :h, '2030-01-01 00:00:00+00') RETURNING id",
            ['e' => $email, 'g' => $parentId, 'h' => $this->hasher->hash('correct horse battery')],
        );
        $this->pdo->prepare('UPDATE player_profiles SET login_user_id = :u WHERE id = :p')->execute(['u' => $kid, 'p' => $profileId]);
        $this->cookies = [];
        $this->login($email);

        return $kid;
    }

    /** @return string the active context stored for the current session, as "organization:profile" */
    protected function storedContext(): string
    {
        $raw = $this->scalar('SELECT active_context FROM sessions WHERE token_hash = :h', ['h' => \App\Identity\Domain\Session::hashToken($this->cookies['__Host-sid'] ?? '')]);
        $ctx = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($ctx) ? (string) $ctx['organization_id'] . ':' . (string) $ctx['profile_id'] : '';
    }
    /** @return array<string, int> row counts of everything a link use could write */
    protected function writeFootprint(): array
    {
        $counts = [];
        foreach (['share_links', 'share_link_uses', 'player_trainer_associations', 'users', 'player_profiles', 'audit_log', 'outbox', 'notifications'] as $table) {
            $counts[$table] = (int) $this->scalar('SELECT count(*) FROM ' . $table);
        }
        $counts['uses'] = (int) $this->scalar('SELECT coalesce(sum(use_count), 0) FROM share_links');

        return $counts;
    }
}
