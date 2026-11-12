<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class StartImpersonationTest extends ImpersonationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    #[Group('AC-50')]
    #[DataProvider('nonAdminRoles')]
    public function testOnlyASuperAdminCanStartImpersonation(string $role): void
    {
        $this->signInAs($role, 'nonadmin@example.com');

        self::assertSame(403, $this->get("/admin/users/{$this->coach}/impersonate")->getStatusCode());
        self::assertSame(403, $this->impersonate($this->coach)->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM impersonations'));
    }

    #[Group('AC-50')]
    public function testAGuestIsSentToSignIn(): void
    {
        $this->cookies = [];

        self::assertSame(302, $this->get("/admin/users/{$this->coach}/impersonate")->getStatusCode());
    }

    #[Group('AC-50')]
    public function testTheConfirmationNamesTheUserAndTheRoleAndStartsNothing(): void
    {
        $page = $this->get("/admin/users/{$this->coach}/impersonate");

        self::assertSame(200, $page->getStatusCode());
        $html = $this->body($page);
        self::assertStringContainsString('View as Cora Coach?', $html);
        self::assertStringContainsString('Coach', $html);
        self::assertStringContainsString('action="/admin/users/' . $this->coach . '/impersonate"', $html);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM impersonations'));
    }

    #[Group('AC-50'), Group('AC-55')]
    public function testStartingSwitchesTheSessionToTheTargetAndLogsIt(): void
    {
        $oldSession = $this->cookies['__Host-sid'];

        $response = $this->impersonate($this->coach);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/coach', $this->location($response));
        self::assertNotSame($oldSession, $this->cookies['__Host-sid'], 'the session identifier is regenerated');
        self::assertNull($this->scalar('SELECT 1 FROM sessions WHERE token_hash = :h', ['h' => \App\Identity\Domain\Session::hashToken($oldSession)]) ?: null);
        $session = $this->sessionRow();
        self::assertSame($this->coach, $session['user_id']);
        self::assertSame($this->admin, $session['impersonator_user_id']);
        $row = $this->impersonationRow();
        self::assertSame($this->admin, $row['admin_user_id']);
        self::assertSame($this->coach, $row['target_user_id']);
        self::assertSame($row['id'], $session['impersonation_id']);
        self::assertNull($row['ended_at']);
        $audit = $this->row("SELECT actor_user_id, subject_id FROM audit_log WHERE action = 'impersonation.started'");
        self::assertSame([$this->admin, $this->coach], [$audit['actor_user_id'] ?? null, $audit['subject_id'] ?? null]);
    }

    #[Group('AC-52')]
    public function testTheMarkerCookieIsSetForTheBrowserSessionOnly(): void
    {
        $response = $this->impersonate($this->coach);

        $line = '';
        foreach ($response->getHeader('Set-Cookie') as $candidate) {
            if (str_starts_with($candidate, '__Host-imp=')) {
                $line = $candidate;
            }
        }
        self::assertStringContainsString('__Host-imp=' . $this->impersonationRow()['id'], $line);
        self::assertStringNotContainsString('Max-Age', $line, 'a session cookie dies with the browser');
        self::assertStringContainsString('Secure', $line);
        self::assertStringContainsString('HttpOnly', $line);
    }

    #[Group('AC-51')]
    public function testASuperAdminCannotBeImpersonatedAndTheRefusalSaysWhy(): void
    {
        $other = $this->createUser('super_admin', 'other.admin@example.com', name: 'Olive Admin');

        $response = $this->impersonate($other);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('A Super Admin cannot be impersonated.', $this->body($response));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM impersonations'));
        self::assertSame($this->admin, $this->sessionRow()['user_id'], 'still the admin');
        self::assertStringContainsString('A Super Admin cannot be impersonated.', $this->body($this->get("/admin/users/{$other}/impersonate")));
    }

    #[Group('AC-50')]
    public function testADeletedUserCannotBeImpersonated(): void
    {
        $this->deactivate($this->coach);
        $this->deleteUser($this->coach);

        $response = $this->impersonate($this->coach);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('A deleted user cannot be impersonated.', $this->body($response));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM impersonations'));
    }

    #[Group('AC-50')]
    public function testAnUnknownUserIsNotFound(): void
    {
        self::assertSame(404, $this->impersonate('00000000-0000-7000-8000-000000000000')->getStatusCode());
        self::assertSame(404, $this->get('/admin/users/not-a-uuid/impersonate')->getStatusCode());
    }

    #[Group('AC-50')]
    public function testAnInactiveUserCanBeViewedForSupport(): void
    {
        $this->deactivate($this->coach);

        $response = $this->impersonate($this->coach);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->coach, $this->sessionRow()['user_id']);
    }

    #[Group('AC-50')]
    public function testTheStartRequiresACsrfToken(): void
    {
        $response = $this->send('POST', "/admin/users/{$this->coach}/impersonate", ['_csrf' => 'wrong']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM impersonations'));
    }
}
