<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Admin\Application\StartImpersonation;
use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Identity\Domain\SessionRepository;
use App\Shared\Http\Exception\Forbidden;
use PHPUnit\Framework\Attributes\Group;

/** Edge case: nested impersonation, and a Super Admin target, are refused. */
final class NestedImpersonationTest extends ImpersonationTestCase
{
    #[Group('AC-50'), Group('AC-51')]
    public function testStartingASecondImpersonationWhileImpersonatingIsRefused(): void
    {
        $other = $this->createUser('player', 'p@example.com', name: 'Pia Player');
        $this->startAsCoach();

        $response = $this->impersonate($other);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM impersonations'));
        self::assertSame($this->coach, $this->sessionRow()['user_id'], 'still the first impersonation');
        self::assertSame(403, $this->get("/admin/users/{$other}/impersonate")->getStatusCode());
    }

    #[Group('AC-51')]
    public function testTheUseCaseItselfRefusesNestingEvenForAnAdminPrincipal(): void
    {
        $this->startAsCoach();
        $sessions = $this->container->get(SessionRepository::class);
        self::assertInstanceOf(SessionRepository::class, $sessions);
        $session = $sessions->find(\App\Identity\Domain\Session::hashToken($this->cookies['__Host-sid']));
        self::assertNotNull($session);
        $state = new SessionState($sessions, $this->clock, $session);
        $adminPrincipal = new CurrentUser($this->admin, 'Ada Admin', self::ADMIN_EMAIL, Role::SuperAdmin, 'standard', false, true);
        $useCase = $this->container->get(StartImpersonation::class);
        self::assertInstanceOf(StartImpersonation::class, $useCase);
        $other = $this->createUser('player', 'p@example.com', name: 'Pia Player');

        $this->expectException(Forbidden::class);
        $useCase->execute($adminPrincipal, $state, $other);
    }

    #[Group('AC-51')]
    public function testAnImpersonatedUserCannotReachTheAdminToolsToImpersonateAnAdmin(): void
    {
        $other = $this->createUser('super_admin', 'other.admin@example.com', name: 'Olive Admin');
        $this->startAsCoach();

        self::assertSame(403, $this->impersonate($other)->getStatusCode());
        self::assertSame(403, $this->get('/admin/users')->getStatusCode());
    }

    #[Group('AC-50')]
    public function testAfterExitingANewImpersonationCanStart(): void
    {
        $other = $this->createUser('player', 'p@example.com', name: 'Pia Player');
        $this->startAsCoach();
        $this->post('/impersonation/exit');

        self::assertSame(302, $this->impersonate($other)->getStatusCode());
        self::assertSame($other, $this->sessionRow()['user_id']);
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM impersonations'));
    }
}
