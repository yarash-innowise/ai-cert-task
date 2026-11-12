<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;
use Tests\Http\TenantIsolation\RecordingRouter;

final class EnableChildLoginTest extends FamilyHttpTestCase
{
    private const PASSWORD = 'a long child password';

    private function enable(string $childId, string $email = 'mia.child@example.com', string $password = self::PASSWORD): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/children/' . $childId . '/login', ['email' => $email, 'password' => $password]);
    }

    #[Group('AC-9')]
    #[Group('AC-37')]
    public function testTheOwningParentCreatesAVerifiedChildAccountWithTheParentAsGuardian(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);

        $response = $this->enable($child, 'Mia.Child@Example.com');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/children?notice=login_enabled', $this->location($response));
        $user = $this->row("SELECT * FROM users WHERE email = 'mia.child@example.com'");
        self::assertIsArray($user);
        self::assertSame('player', $user['role']);
        self::assertSame('child', $user['account_kind']);
        self::assertSame($parent, $user['guardian_user_id']);
        self::assertSame('Mia Smith', $user['name']);
        self::assertNotNull($user['email_verified_at']);
        self::assertFalse($user['must_set_password']);
        self::assertStringStartsWith('$argon2id$', (string) $user['password_hash']);
        self::assertSame($user['id'], $this->scalar('SELECT login_user_id FROM player_profiles WHERE id = :p', ['p' => $child]));
        $audit = $this->row("SELECT payload, actor_user_id FROM audit_log WHERE action = 'child_login.enabled'");
        self::assertIsArray($audit);
        self::assertSame($parent, $audit['actor_user_id']);
        self::assertStringNotContainsString('mia.child', (string) $audit['payload']);
    }

    #[Group('AC-9')]
    #[Group('AC-37')]
    public function testTheChildSignsInAndLandsOnTheFamilyPageWithExactlyOneRole(): void
    {
        $parent = $this->signInAs('player');
        $this->enable($this->childOf($parent));
        $this->cookies = [];

        $response = $this->login('mia.child@example.com', self::PASSWORD);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family', $this->location($response));
        self::assertSame(200, $this->get('/family')->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT count(DISTINCT role) FROM users WHERE email = 'mia.child@example.com'"));
        foreach (['/admin', '/trainer', '/coach'] as $forbidden) {
            self::assertSame(403, $this->get($forbidden)->getStatusCode(), $forbidden);
        }
    }

    #[Group('AC-9')]
    public function testOnlyTheOwningParentCanEnableTheLogin(): void
    {
        $owner = $this->createUser('player', 'owner@example.com');
        $child = $this->childOf($owner);
        $this->signInAs('player');

        self::assertSame(404, $this->get('/family/children/' . $child . '/login')->getStatusCode());
        self::assertSame(404, $this->enable($child)->getStatusCode());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
    }

    #[Group('AC-9')]
    public function testOtherRolesAndGuestsAreRefused(): void
    {
        $owner = $this->createUser('player', 'owner@example.com');
        $child = $this->childOf($owner);

        $this->cookies = [];
        self::assertSame(302, $this->get('/family/children/' . $child . '/login')->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($this->get('/family/children/' . $child . '/login')));

        $this->signInAs('trainer');
        self::assertSame(403, $this->enable($child)->getStatusCode());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
    }

    #[Group('AC-37')]
    public function testAChildLoginCannotCreateChildrenOrLoginsForOthers(): void
    {
        $parent = $this->signInAs('player');
        $mine = $this->childOf($parent);
        $sibling = $this->childOf($parent, 'Sam', 'Smith', '2020-02-02', 'male');
        $this->enable($mine);
        $this->cookies = [];
        $this->login('mia.child@example.com', self::PASSWORD);

        self::assertSame(403, $this->addChild(['first_name' => 'Intruder'])->getStatusCode());
        // Refused as forbidden by direct address (AC-47): the child-login guard answers before any ownership lookup.
        self::assertSame(403, $this->enable($sibling, 'sam@example.com')->getStatusCode());
        self::assertSame(2, $this->childCount($parent));
    }

    #[Group('AC-37')]
    public function testOnlyOneLoginPerChild(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        $this->enable($child);

        $second = $this->enable($child, 'another@example.com');

        self::assertSame(422, $second->getStatusCode());
        self::assertStringContainsString('already has a login', $this->body($second));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
        self::assertSame(404, $this->get('/family/children/' . $child . '/login')->getStatusCode());
    }

    #[Group('AC-37')]
    public function testADuplicateEmailIsRefusedAnyLetterCaseAndCreatesNothing(): void
    {
        $parent = $this->signInAs('player');
        $this->createUser('player', 'taken@example.com');
        $child = $this->childOf($parent);

        $response = $this->enable($child, 'TAKEN@example.com');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('already registered', $this->body($response));
        self::assertNull($this->scalar('SELECT login_user_id FROM player_profiles WHERE id = :p', ['p' => $child]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
    }

    #[Group('AC-37')]
    public function testAWeakPasswordIsRefusedAndNeverEchoed(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);

        $short = $this->enable($child, 'mia.child@example.com', 'short');
        $common = $this->enable($child, 'mia.child@example.com', 'password123');

        foreach ([$short, $common] as $response) {
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('href="#field-password"', $this->body($response));
        }
        self::assertStringNotContainsString('value="short"', $this->body($short));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
    }

    #[Group('AC-37')]
    public function testThereIsNoPublicRouteThatCreatesAChildAccount(): void
    {
        $router = new RecordingRouter();
        $routes = require __DIR__ . '/../../../config/routes.php';
        $routes($router, $this->container);

        $public = array_filter($router->table(), static fn(string $r): bool => preg_match('#register|signup|child#i', $r) === 1 && !str_starts_with(explode(' ', $r, 2)[1], '/family')
            // Registration through a Trainer link creates a standard (parent / adult) account only, never a child account (task 21)
            && !str_contains($r, '/join/{code}/register')
            // The camp-form pre-fill registration likewise creates a standard account only (task 42, PrefillRegistrationTest)
            && !str_contains($r, '/register/prefill/{token}'));

        self::assertSame([], array_values($public));
        $this->cookies = [];
        self::assertSame(404, $this->get('/register')->getStatusCode());
        self::assertContains($this->post('/register', ['email' => 'kid@example.com'])->getStatusCode(), [403, 404]);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE account_kind = 'child'"));
    }
}
