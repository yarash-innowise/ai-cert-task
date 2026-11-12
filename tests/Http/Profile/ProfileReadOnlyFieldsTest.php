<?php

declare(strict_types=1);

namespace Tests\Http\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** AC-69: email, role, skill level and the account-created date are read-only to the user. */
#[Group('AC-69')]
final class ProfileReadOnlyFieldsTest extends ProfileHttpTestCase
{
    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['player' => ['player'], 'trainer' => ['trainer'], 'coach' => ['coach'], 'super admin' => ['super_admin']];
    }

    /** Signs in as the role (a Trainer gets an organization) and returns the user id. */
    private function signInRole(string $role): string
    {
        if ($role === 'trainer') {
            $this->signInAsTrainer();

            return $this->trainerId;
        }

        return $this->signInAs($role, 'read.only@example.com');
    }

    #[DataProvider('roles')]
    public function testEmailAndRoleAreShownButOfferedNoInputOnTheOwnProfilePage(string $role): void
    {
        $user = $this->signInRole($role);
        $email = (string) $this->user($user)['email'];

        $html = $this->body($this->get('/profile'));

        self::assertStringContainsString($email, $html);
        self::assertMatchesRegularExpression('/<dt>Role<\/dt><dd>/', $html);
        self::assertDoesNotMatchRegularExpression('/<input[^>]+name="(email|role|status|skill_level|created_at|version)"/', $html);
        self::assertDoesNotMatchRegularExpression('/<select[^>]+name="(email|role|status|skill_level)"/', $html);
        self::assertStringContainsString('Account created', $html);
    }

    #[DataProvider('roles')]
    public function testATamperedPostWithEmailRoleStatusSkillLevelAndCreatedDateChangesNone(string $role): void
    {
        $user = $this->signInRole($role);
        $email = (string) $this->user($user)['email'];
        $profile = $role === 'player' ? $this->selfOf($user) : null;
        $before = $this->row('SELECT email, role, status, account_kind, created_at, must_set_password, email_verified_at FROM users WHERE id = :i', ['i' => $user]);

        $response = $this->saveProfile([
            'name' => 'Changed Name',
            'business_name' => 'Ace Tennis Academy',
            'email' => 'attacker@example.com',
            'role' => 'super_admin',
            'status' => 'inactive',
            'account_kind' => 'child',
            'skill_level' => 'elite',
            'created_at' => '2000-01-01 00:00:00+00',
            'must_set_password' => '1',
            'guardian_user_id' => $user,
            'version' => '99',
        ]);

        self::assertSame(302, $response->getStatusCode(), 'the allowed part is saved, the rest is ignored');
        self::assertSame('Changed Name', $this->user($user)['name']);
        self::assertSame($before, $this->row('SELECT email, role, status, account_kind, created_at, must_set_password, email_verified_at FROM users WHERE id = :i', ['i' => $user]));
        if ($profile !== null) {
            self::assertNull($this->scalar('SELECT skill_level FROM player_profiles WHERE id = :p', ['p' => $profile]));
        }
        self::assertSame($email, $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $user]));
    }

    public function testSkillLevelSetByTheTrainerIsShownToThePlayerButNotEditable(): void
    {
        [, $profile] = $this->signInAsPlayerWithSelfProfile();
        $this->pdo->prepare("UPDATE player_profiles SET skill_level = 'advanced' WHERE id = :p")->execute(['p' => $profile]);

        $html = $this->body($this->get('/profile'));
        $this->saveProfile(['skill_level' => 'beginner']);

        self::assertStringContainsString('<dt>Skill level</dt><dd>Advanced</dd>', $html);
        self::assertSame('advanced', $this->scalar('SELECT skill_level FROM player_profiles WHERE id = :p', ['p' => $profile]));
    }

    public function testThereIsNoRouteToEditAnotherUsersProfile(): void
    {
        $other = $this->createUser('coach', 'other@example.com', name: 'Other Coach');
        $this->signInAs('trainer');

        $this->saveProfile(['name' => 'Hijacked', 'user_id' => $other, 'id' => $other]);

        self::assertSame('Other Coach', $this->user($other)['name']);
        self::assertSame(404, $this->post('/profile/' . $other)->getStatusCode());
    }
}
