<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

/** Q-01.14 (parent) and Q-01.15 (Trainer) cascades, and the carry-over from task 30 (auto-deny pending requests). */
final class DeactivateCascadeTest extends LifecycleTestCase
{
    #[Group('AC-72'), Group('AC-44')]
    public function testDeactivatingAParentAutoDeniesPendingChildRequestsInTheSameAction(): void
    {
        $pending = $this->openRequest();
        $info = $this->openRequest('rsvp', 'info_requested', self::EVENT_2, payment: 'none', amount: 0);
        $approved = $this->openRequest('purchase', 'approved', '0190aaaa-0000-7000-8000-0000000000e3');

        $this->deactivate($this->parent);

        self::assertSame('auto_denied', $this->requestStatus($pending));
        self::assertSame('auto_denied', $this->requestStatus($info));
        self::assertSame('approved', $this->requestStatus($approved));
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'approval.auto_denied' AND actor_user_id = :a", ['a' => $this->admin]));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_auto_denied' AND user_id = :u AND payload->>'request_id' = :r", ['u' => $this->kid, 'r' => $pending]));
    }

    #[Group('AC-72'), Group('AC-44')]
    public function testDeletingAParentAutoDeniesPendingChildRequestsToo(): void
    {
        $pending = $this->openRequest();

        $this->deleteUser($this->parent);

        self::assertSame('auto_denied', $this->requestStatus($pending));
    }

    #[Group('AC-72')]
    public function testTheChildLoginIsDeactivatedWithTheParentAndItsSessionsEnd(): void
    {
        $kidJar = $this->signInWith(self::KID_EMAIL);
        $this->signInWith(self::ADMIN_EMAIL);

        $this->deactivate($this->parent);

        self::assertSame('inactive', $this->statusOf($this->kid));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->kid]));
        self::assertSame(302, $this->get('/family/requests', [], $kidJar)->getStatusCode());
        $this->cookies = [];
        self::assertStringContainsString('Account deactivated. Contact support.', $this->body($this->login(self::KID_EMAIL)));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_profiles WHERE id = :p', ['p' => $this->mia]), 'the child profile stays');
    }

    #[Group('AC-75')]
    public function testReactivatingTheParentReactivatesTheChildLoginAndAChildCannotComeBackAlone(): void
    {
        $this->deactivate($this->parent);

        $alone = $this->reactivate($this->kid);
        self::assertSame(422, $alone->getStatusCode());
        self::assertStringContainsString('Reactivate the parent first', $this->body($alone));
        self::assertSame('inactive', $this->statusOf($this->kid));

        $this->reactivate($this->parent);

        self::assertSame('active', $this->statusOf($this->kid));
        $this->cookies = [];
        self::assertSame(302, $this->login(self::KID_EMAIL)->getStatusCode());
    }

    #[Group('AC-72')]
    public function testDeactivatingATrainerMakesTheOrganizationInactiveAndItsLinkUnusable(): void
    {
        [, $code] = $this->shareLink($this->org);
        $this->signInWith(self::ADMIN_EMAIL);
        self::assertStringContainsString('Ace Tennis Academy', $this->body($this->get('/join/' . $code, [], [])), 'precondition: the link works');

        $this->deactivate($this->trainer);

        self::assertSame('inactive', $this->statusOf($this->trainer));
        self::assertSame('inactive', $this->scalar('SELECT status FROM organizations WHERE id = :o', ['o' => $this->org]));
        $landing = $this->body($this->get('/join/' . $code, [], []));
        self::assertStringContainsString('This link is not valid', $landing);
        self::assertStringNotContainsString('Ace Tennis Academy', $landing);
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->trainer]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM organizations WHERE id = :o', ['o' => $this->org]), 'the organization and its history stay');
    }

    #[Group('AC-75')]
    public function testReactivatingTheTrainerReactivatesTheOrganization(): void
    {
        $this->deactivate($this->trainer);

        $this->reactivate($this->trainer);

        self::assertSame('active', $this->scalar('SELECT status FROM organizations WHERE id = :o', ['o' => $this->org]));
    }

    #[Group('AC-72')]
    public function testAnInactiveTrainersCoachLosesTheContextWhileTheMembershipStays(): void
    {
        $coach = $this->activeCoach();
        $options = $this->container->get(\App\Shared\Domain\ContextOptions::class);
        self::assertInstanceOf(\App\Shared\Domain\ContextOptions::class, $options);
        self::assertCount(1, $options->for($coach, 'coach'), 'precondition: the coach acts in the organization');

        $this->deactivate($this->trainer);

        self::assertSame([], $options->for($coach, 'coach'), 'the organization is unavailable as a context');
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $coach]));
    }
}
