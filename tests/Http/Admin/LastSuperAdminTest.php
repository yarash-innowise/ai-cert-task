<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Admin\Application\DeactivateUser;
use App\Admin\Application\DeleteUser;
use App\Shared\Http\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\Group;

/** Edge cases: an admin removing themselves, and removing the last active Super Admin. */
final class LastSuperAdminTest extends LifecycleTestCase
{
    #[Group('AC-72')]
    public function testASuperAdminCannotDeactivateTheirOwnAccount(): void
    {
        $response = $this->deactivate($this->admin);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('You cannot deactivate your own account.', $this->body($response));
        self::assertSame('active', $this->statusOf($this->admin));
    }

    #[Group('AC-76')]
    public function testASuperAdminCannotDeleteTheirOwnAccount(): void
    {
        $response = $this->deleteUser($this->admin);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('You cannot delete your own account.', $this->body($response));
        self::assertSame('active', $this->statusOf($this->admin));
        self::assertSame(self::ADMIN_EMAIL, $this->emailOf($this->admin));
    }

    #[Group('AC-72')]
    public function testAnotherSuperAdminCanBeDeactivatedWhileTwoAreActive(): void
    {
        $second = $this->createUser('super_admin', 'second.admin@example.com', name: 'Sam Second');

        self::assertSame(302, $this->deactivate($second)->getStatusCode());
        self::assertSame('inactive', $this->statusOf($second));
    }

    #[Group('AC-72')]
    public function testTheLastActiveSuperAdminCannotBeDeactivatedEvenByAnInactiveOne(): void
    {
        // The admin signed in is the only active one; the actor below is an inactive Super Admin (a stale session or a script).
        $stale = $this->createUser('super_admin', 'stale.admin@example.com', status: 'inactive', name: 'Stale Admin');
        $useCase = $this->container->get(DeactivateUser::class);
        self::assertInstanceOf(DeactivateUser::class, $useCase);

        try {
            $useCase->execute($this->admin, $stale);
            self::fail('The last active Super Admin was deactivated.');
        } catch (ValidationFailed $e) {
            self::assertSame(['user' => 'The last active Super Admin cannot be deactivated.'], $e->errors);
        }
        self::assertSame('active', $this->statusOf($this->admin));
    }

    #[Group('AC-76')]
    public function testTheLastActiveSuperAdminCannotBeDeleted(): void
    {
        $stale = $this->createUser('super_admin', 'stale.admin@example.com', status: 'inactive', name: 'Stale Admin');
        $useCase = $this->container->get(DeleteUser::class);
        self::assertInstanceOf(DeleteUser::class, $useCase);

        try {
            $useCase->execute($this->admin, 'Test', $stale);
            self::fail('The last active Super Admin was deleted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['user' => 'The last active Super Admin cannot be deleted.'], $e->errors);
        }
        self::assertSame('active', $this->statusOf($this->admin));
        self::assertSame(self::ADMIN_EMAIL, $this->emailOf($this->admin));
    }
}
