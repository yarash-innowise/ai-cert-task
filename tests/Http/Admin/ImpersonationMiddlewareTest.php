<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Admin\Http\ImpersonationMiddleware;
use PHPUnit\Framework\Attributes\Group;

final class ImpersonationMiddlewareTest extends ImpersonationTestCase
{
    /** Page body without the parts that differ per session (CSRF token, CSP nonce, the banner). */
    private function normalized(string $html): string
    {
        $html = (string) preg_replace('#<div class="impersonation-banner".*?</form>\s*(<p class="impersonation-banner__text">[^<]*</p>)?\s*</div>\s*</div>#s', '', $html);

        $html = (string) preg_replace(['/nonce="[^"]*"/', '/name="_csrf" value="[^"]*"/'], ['nonce=""', 'name="_csrf" value=""'], $html);

        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    #[Group('AC-52')]
    public function testNavigationPermissionsAndDataMatchTheImpersonatedUser(): void
    {
        $coachOwn = $this->signInWith('coach@example.com');
        $own = [];
        foreach (['/coach', '/coach/schedule', '/profile'] as $path) {
            $own[$path] = $this->get($path, [], $coachOwn);
            self::assertSame(200, $own[$path]->getStatusCode(), $path);
        }
        $this->signInWith(self::ADMIN_EMAIL);
        $this->startAsCoach();

        foreach (['/coach', '/coach/schedule', '/profile'] as $path) {
            $viewed = $this->get($path);
            self::assertSame(200, $viewed->getStatusCode(), $path);
            self::assertSame($this->normalized($this->body($own[$path])), $this->normalized($this->body($viewed)), 'same page as the Coach sees: ' . $path);
        }
        // permissions: what a Coach may not open stays closed to the impersonating admin
        foreach (['/admin', '/admin/users', '/trainer/players', '/admin/impersonations', '/family'] as $path) {
            self::assertSame(403, $this->get($path)->getStatusCode(), $path);
        }
        $nav = $this->body($this->get('/coach'));
        self::assertStringContainsString('My Times', $nav);
        self::assertStringNotContainsString('/admin/users', $nav);
    }

    #[Group('AC-53')]
    public function testExitReturnsToTheSuperAdminsOwnView(): void
    {
        $this->startAsCoach();
        $during = $this->cookies['__Host-sid'];

        $response = $this->post('/impersonation/exit');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=impersonation_ended', $this->location($response));
        self::assertNotSame($during, $this->cookies['__Host-sid'], 'the identifier is regenerated again');
        self::assertArrayNotHasKey('__Host-imp', $this->cookies, 'the marker cookie is cleared');
        $session = $this->sessionRow();
        self::assertSame($this->admin, $session['user_id']);
        self::assertNull($session['impersonation_id']);
        self::assertNull($session['impersonator_user_id']);
        $users = $this->get('/admin/users?notice=impersonation_ended');
        self::assertSame(200, $users->getStatusCode());
        self::assertStringContainsString('You are back in your own view.', $this->body($users));
        self::assertStringNotContainsString('impersonation-banner', $this->body($users));
        self::assertSame(200, $this->get('/admin/impersonations')->getStatusCode());
        $row = $this->impersonationRow();
        self::assertSame('manual', $row['end_reason']);
        self::assertNotNull($row['ended_at']);
    }

    #[Group('AC-53')]
    public function testExitWithoutAnImpersonationIsNotFound(): void
    {
        self::assertSame(404, $this->post('/impersonation/exit')->getStatusCode());
    }

    #[Group('AC-54')]
    public function testTheImpersonationEndsAutomaticallyAfterOneHour(): void
    {
        $this->startAsCoach();
        $started = new \DateTimeImmutable((string) $this->impersonationRow()['started_at']);

        $this->clock->advance('59 minutes');
        self::assertSame(200, $this->get('/coach')->getStatusCode(), 'still within the hour');

        $this->clock->advance('2 minutes');
        $response = $this->get('/coach');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=impersonation_timeout', $this->location($response));
        $row = $this->impersonationRow();
        self::assertSame('timeout', $row['end_reason']);
        self::assertEquals($started->modify('+1 hour'), new \DateTimeImmutable((string) $row['ended_at']), 'the end is start + 1 hour, not the time of the late request');
        self::assertSame($this->admin, $this->sessionRow()['user_id']);
        $page = $this->get('/admin/users?notice=impersonation_timeout');
        self::assertStringContainsString('ended after 1 hour', $this->body($page));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.ended' AND payload->>'end_reason' = 'timeout' AND (payload->>'duration_seconds')::int = 3600"));
    }

    #[Group('AC-54')]
    public function testAMissingMarkerCookieMeansTheBrowserWasClosedAndEndsTheImpersonation(): void
    {
        $this->startAsCoach();
        $this->clock->advance('10 minutes');
        self::assertSame(200, $this->get('/coach')->getStatusCode());
        unset($this->cookies['__Host-imp']);

        $response = $this->get('/coach');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=impersonation_browser_closed', $this->location($response));
        $row = $this->impersonationRow();
        self::assertSame('browser_closed', $row['end_reason']);
        self::assertNotNull($row['ended_at']);
        self::assertSame($this->admin, $this->sessionRow()['user_id']);
    }

    #[Group('AC-54')]
    public function testAMarkerCookieFromAnotherImpersonationDoesNotCount(): void
    {
        $this->startAsCoach();
        $this->cookies['__Host-imp'] = '00000000-0000-7000-8000-000000000000';

        self::assertSame('/admin/users?notice=impersonation_browser_closed', $this->location($this->get('/coach')));
    }

    #[Group('AC-55')]
    public function testEveryStateChangingRequestIsAuditedWithTheAdminsIdentityAndReadsAreNot(): void
    {
        $this->startAsCoach();
        $this->get('/coach');
        $this->get('/profile');
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.action'"));

        $response = $this->post('/profile', ['name' => 'Cora Renamed']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('Cora Renamed', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->coach]), 'the change itself works as the user');
        $stmt = $this->pdo->query("SELECT actor_user_id, impersonator_user_id, payload FROM audit_log WHERE action = 'impersonation.action'");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->coach, $rows[0]['actor_user_id']);
        self::assertSame($this->admin, $rows[0]['impersonator_user_id'], 'logged with the admin identity');
        $payload = json_decode((string) $rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->impersonationRow()['id'], $payload['impersonation_id']);
        self::assertSame('POST', $payload['method']);
        self::assertSame('/profile', $payload['path']);
    }

    #[Group('AC-55')]
    public function testTheLoggedPathHidesAnythingThatCouldBeASecret(): void
    {
        self::assertSame('/setup/*', ImpersonationMiddleware::redact('/setup/Ab3dEf9GhIjKlMnOpQrStUvWxYz0123456789'));
        self::assertSame('/join/*/who', ImpersonationMiddleware::redact('/join/K7P2QX/who'));
        self::assertSame('/coach/assignments/0190aaaa-0000-7000-8000-0000000000e1/accept', ImpersonationMiddleware::redact('/coach/assignments/0190aaaa-0000-7000-8000-0000000000e1/accept'));
        self::assertSame('/family/children/new', ImpersonationMiddleware::redact('/family/children/new'));
    }

    #[Group('AC-55')]
    public function testStartAndEndAreLoggedWithAdminTargetStartEndAndDuration(): void
    {
        $this->startAsCoach();
        $this->clock->advance('7 minutes 30 seconds');
        $this->get('/coach');
        $this->post('/impersonation/exit');

        $row = $this->impersonationRow();
        self::assertSame($this->admin, $row['admin_user_id']);
        self::assertSame($this->coach, $row['target_user_id']);
        self::assertNotNull($row['started_at']);
        self::assertNotNull($row['ended_at']);
        $end = $this->row("SELECT payload FROM audit_log WHERE action = 'impersonation.ended'");
        self::assertIsArray($end);
        $payload = json_decode((string) $end['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('manual', $payload['end_reason']);
        self::assertSame(450, $payload['duration_seconds']);
    }

    #[Group('AC-52')]
    public function testAnInactiveUserIsViewedReadOnly(): void
    {
        $this->deactivate($this->coach);
        $this->startAsCoach();

        self::assertSame(200, $this->get('/coach')->getStatusCode(), 'looking is allowed');
        $banner = $this->body($this->get('/profile'));
        self::assertStringContainsString('view only', $banner);

        $refused = $this->post('/profile', ['name' => 'Changed By Admin']);

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('Cora Coach', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->coach]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.action' AND payload->>'refused' = 'true'"), 'the refused attempt is logged too');
        self::assertSame(302, $this->post('/impersonation/exit')->getStatusCode(), 'leaving is always possible');
        self::assertSame($this->admin, $this->sessionRow()['user_id']);
    }

    #[Group('AC-52')]
    public function testTheAdminNeverSetsTheUsersFirstPassword(): void
    {
        $this->pdo->prepare('UPDATE users SET must_set_password = true, password_hash = NULL WHERE id = :u')->execute(['u' => $this->coach]);
        $this->startAsCoach();

        self::assertSame(200, $this->get('/coach')->getStatusCode(), 'no redirect to the first-password page');
        self::assertSame(403, $this->post('/password/set', ['password' => 'a password the admin picked', 'password_confirmation' => 'a password the admin picked'])->getStatusCode());
        self::assertNull($this->scalar('SELECT password_hash FROM users WHERE id = :u', ['u' => $this->coach]));
    }

    #[Group('AC-54')]
    public function testDeletingTheImpersonatedUserEndsTheImpersonationAndReturnsTheAdmin(): void
    {
        $this->startAsCoach();
        $this->pdo->prepare("UPDATE users SET status = 'deleted' WHERE id = :u")->execute(['u' => $this->coach]);

        $response = $this->get('/coach');

        self::assertSame('/admin/users?notice=impersonation_target_deleted', $this->location($response));
        self::assertSame('target_unavailable', $this->impersonationRow()['end_reason']);
        self::assertSame($this->admin, $this->sessionRow()['user_id']);
    }

    #[Group('AC-55')]
    public function testSigningOutWhileImpersonatingClosesTheLogAndEndsTheSession(): void
    {
        $this->startAsCoach();

        $response = $this->post('/logout');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('logout', $this->impersonationRow()['end_reason']);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id IN (:a, :c)', ['a' => $this->admin, 'c' => $this->coach]));
        self::assertArrayNotHasKey('__Host-sid', $this->cookies);
        self::assertArrayNotHasKey('__Host-imp', $this->cookies);
    }

    #[Group('AC-52')]
    public function testADeactivatedAdminLosesTheWholeSessionEvenWhileImpersonating(): void
    {
        $this->startAsCoach();
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :u")->execute(['u' => $this->admin]);

        $response = $this->get('/coach');

        self::assertStringStartsWith('/login', $this->location($response));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id IN (:a, :c)', ['a' => $this->admin, 'c' => $this->coach]));
        self::assertNotNull($this->impersonationRow()['ended_at']);
    }
}
