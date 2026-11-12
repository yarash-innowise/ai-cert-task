<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

final class DeactivateUserTest extends LifecycleTestCase
{
    #[Group('AC-72')]
    public function testTheConfirmationPageNamesTheUserAndTheRoleAndChangesNothing(): void
    {
        $page = $this->get("/admin/users/{$this->parent}/deactivate");

        self::assertSame(200, $page->getStatusCode());
        $html = $this->body($page);
        self::assertStringContainsString('Deactivate Pat Parent?', $html);
        self::assertStringContainsString('Player', $html);
        self::assertStringContainsString('action="/admin/users/' . $this->parent . '/deactivate"', $html);
        self::assertSame('active', $this->statusOf($this->parent));
    }

    #[Group('AC-72')]
    public function testDeactivatingSetsStatusInactiveAndWritesAnAuditEntry(): void
    {
        $response = $this->deactivate($this->parent);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=user_deactivated', $this->location($response));
        self::assertSame('inactive', $this->statusOf($this->parent));
        $audit = $this->row("SELECT actor_user_id, subject_id, payload FROM audit_log WHERE action = 'user.deactivated' AND subject_id = :i", ['i' => $this->parent]);
        self::assertIsArray($audit);
        self::assertSame($this->admin, $audit['actor_user_id']);
        self::assertStringNotContainsString('parent@example.com', (string) $audit['payload']);
        $list = $this->body($this->get('/admin/users?status=inactive'));
        self::assertStringContainsString('Pat Parent', $list);
        self::assertStringContainsString('badge--inactive', $list);
    }

    #[Group('AC-73')]
    public function testAnInactiveUserCannotSignInAndSeesTheDeactivatedMessage(): void
    {
        $this->deactivate($this->parent);

        $this->cookies = [];
        $response = $this->login(self::PARENT_EMAIL);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Account deactivated. Contact support.', $this->body($response));
        self::assertArrayNotHasKey('__Host-sid', $this->cookies);
    }

    #[Group('AC-73')]
    public function testEveryExistingSessionOfTheUserEndsAtOnceAndAtTheNextRequest(): void
    {
        $phone = $this->signInWith(self::PARENT_EMAIL);
        $laptop = $this->signInWith(self::PARENT_EMAIL);
        $this->signInWith(self::ADMIN_EMAIL);
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->parent]));

        $this->deactivate($this->parent);

        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->parent]));
        foreach ([$phone, $laptop] as $jar) {
            $response = $this->get('/family', [], $jar);
            self::assertSame(302, $response->getStatusCode());
            self::assertStringStartsWith('/login', $this->location($response));
        }
    }

    #[Group('AC-74')]
    public function testTheHistoryOfAnInactiveUserStaysAndIsMarkedInactive(): void
    {
        $self = $this->selfOf($this->parent);
        $this->associate($self, $this->org);
        $request = $this->openRequest('rsvp', 'approved', self::EVENT_2, payment: 'none', amount: 0);
        $before = [
            'profiles' => (int) $this->scalar('SELECT count(*) FROM player_profiles'),
            'associations' => (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'),
            'requests' => (int) $this->scalar('SELECT count(*) FROM approval_requests'),
            'users' => (int) $this->scalar('SELECT count(*) FROM users'),
        ];

        $this->deactivate($this->parent);

        self::assertSame($before, [
            'profiles' => (int) $this->scalar('SELECT count(*) FROM player_profiles'),
            'associations' => (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'),
            'requests' => (int) $this->scalar('SELECT count(*) FROM approval_requests'),
            'users' => (int) $this->scalar('SELECT count(*) FROM users'),
        ], 'nothing is deleted: analytics, rosters and CRM keep their rows');
        self::assertSame('approved', $this->requestStatus($request), 'a decided request is history and stays as it was');
        $this->signInWith($this->trainerEmail());
        $roster = $this->body($this->get('/trainer/players'));
        self::assertStringContainsString('Pat Parent', $roster);
        self::assertStringContainsString('Mia Smith', $roster);
        self::assertStringContainsString('badge--inactive', $roster);
    }

    #[Group('AC-72')]
    public function testOnlyASuperAdminMayDeactivate(): void
    {
        $this->signInAs('trainer', 'other.trainer@example.com');

        self::assertSame(403, $this->get("/admin/users/{$this->parent}/deactivate")->getStatusCode());
        self::assertSame(403, $this->deactivate($this->parent)->getStatusCode());
        self::assertSame('active', $this->statusOf($this->parent));
    }

    #[Group('AC-72')]
    public function testUnknownUsersAndRepeatedDeactivationAreRefused(): void
    {
        self::assertSame(404, $this->deactivate('00000000-0000-7000-8000-000000000000')->getStatusCode());
        $this->deactivate($this->parent);

        $again = $this->deactivate($this->parent);

        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('already inactive', $this->body($again));
    }
}
