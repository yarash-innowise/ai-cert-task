<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Policy\OrganizationPolicy;
use App\Identity\Domain\Policy\UserPolicy;
use App\Identity\Domain\Role;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\RequestContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-9')]
#[Group('AC-10')]
final class PoliciesTest extends TestCase
{
    private const ORG_A = '0190aaaa-0000-7000-8000-00000000000a';
    private const ORG_B = '0190aaaa-0000-7000-8000-00000000000b';

    private function user(Role $role, string $id = 'u1'): CurrentUser
    {
        return new CurrentUser($id, 'Name', 'n@example.com', $role, 'standard', false, true);
    }

    private function in(string $org): RequestContext
    {
        return new RequestContext(OrganizationId::fromString($org));
    }

    public function testOrganizationAccessIsLimitedToTheActiveOrganizationExceptForSuperAdmin(): void
    {
        $policy = new OrganizationPolicy();
        $orgB = OrganizationId::fromString(self::ORG_B);

        self::assertTrue($policy->canAccess($this->user(Role::Trainer), $this->in(self::ORG_B), $orgB));
        self::assertFalse($policy->canAccess($this->user(Role::Trainer), $this->in(self::ORG_A), $orgB));
        self::assertFalse($policy->canAccess($this->user(Role::Coach), RequestContext::none(), $orgB));
        self::assertTrue($policy->canAccess($this->user(Role::SuperAdmin), RequestContext::none(), $orgB));
    }

    public function testOnlyTheOwningTrainerAndSuperAdminManageAnOrganization(): void
    {
        $policy = new OrganizationPolicy();
        $orgA = OrganizationId::fromString(self::ORG_A);

        self::assertTrue($policy->canManage($this->user(Role::Trainer), $this->in(self::ORG_A), $orgA));
        self::assertFalse($policy->canManage($this->user(Role::Trainer), $this->in(self::ORG_B), $orgA));
        self::assertFalse($policy->canManage($this->user(Role::Coach), $this->in(self::ORG_A), $orgA));
        self::assertFalse($policy->canManage($this->user(Role::Player), $this->in(self::ORG_A), $orgA));
        self::assertTrue($policy->canManage($this->user(Role::SuperAdmin), RequestContext::none(), $orgA));
    }

    public function testATrainerManagesOnlyUsersOfTheirOwnOrganization(): void
    {
        $policy = new UserPolicy();
        $inA = [OrganizationId::fromString(self::ORG_A)];
        $inB = [OrganizationId::fromString(self::ORG_B)];
        $trainer = $this->user(Role::Trainer, 'trainer');

        self::assertTrue($policy->canManage($trainer, $this->in(self::ORG_A), 'player', $inA));
        self::assertFalse($policy->canManage($trainer, $this->in(self::ORG_A), 'player', $inB));
        self::assertFalse($policy->canView($trainer, $this->in(self::ORG_A), 'player', $inB));
        self::assertFalse($policy->canManage($trainer, RequestContext::none(), 'player', $inA));
        self::assertTrue($policy->canView($trainer, RequestContext::none(), 'trainer', []), 'own record');
    }

    public function testOtherRolesNeverManageUsersAndOnlySuperAdminEditsAccounts(): void
    {
        $policy = new UserPolicy();
        $inA = [OrganizationId::fromString(self::ORG_A)];

        foreach ([Role::Coach, Role::Player] as $role) {
            self::assertFalse($policy->canManage($this->user($role), $this->in(self::ORG_A), 'other', $inA), $role->value);
            self::assertFalse($policy->canEditAccount($this->user($role)));
        }
        self::assertFalse($policy->canEditAccount($this->user(Role::Trainer)));
        self::assertTrue($policy->canEditAccount($this->user(Role::SuperAdmin)));
        self::assertTrue($policy->canManage($this->user(Role::SuperAdmin), RequestContext::none(), 'other', []));
    }
}
