<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

final class ReactivateUserTest extends LifecycleTestCase
{
    #[Group('AC-75')]
    public function testReactivatingRestoresActiveAndTheUserCanSignInAgain(): void
    {
        $this->deactivate($this->parent);

        $response = $this->reactivate($this->parent);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=user_reactivated', $this->location($response));
        self::assertSame('active', $this->statusOf($this->parent));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'user.reactivated' AND subject_id = :i", ['i' => $this->parent]));
        $this->cookies = [];
        self::assertSame(302, $this->login(self::PARENT_EMAIL)->getStatusCode());
        self::assertSame(200, $this->get('/family')->getStatusCode());
    }

    #[Group('AC-75')]
    public function testAnActiveUserCannotBeReactivated(): void
    {
        $response = $this->reactivate($this->parent);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('already active', $this->body($response));
    }

    #[Group('AC-78')]
    public function testADeletedUserCannotBeReactivated(): void
    {
        $this->deleteUser($this->parent);

        $response = $this->reactivate($this->parent);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('A deleted user cannot be reactivated.', $this->body($response));
        self::assertSame('deleted', $this->statusOf($this->parent));
    }

    #[Group('AC-75')]
    public function testOnlyASuperAdminMayReactivate(): void
    {
        $this->deactivate($this->parent);
        $this->signInAs('coach', 'a.coach@example.com');

        self::assertSame(403, $this->reactivate($this->parent)->getStatusCode());
        self::assertSame('inactive', $this->statusOf($this->parent));
    }
}
