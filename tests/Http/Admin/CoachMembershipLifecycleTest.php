<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Organization\Domain\CoachInvitationToken;
use PHPUnit\Framework\Attributes\Group;

/** F-09: a Coach is active under one Trainer (AC-59) - a deleted or deactivated Trainer does not strand them. */
#[Group('AC-59')]
final class CoachMembershipLifecycleTest extends ImpersonationTestCase
{
    /** @return array{string, string} organization id and the token of an open invitation for the Coach */
    private function otherTrainerInvites(string $email, string $business = 'Beta Academy'): array
    {
        [$owner, $org] = $this->trainerWithOrganization($business);
        $token = CoachInvitationToken::generate();
        $this->pdo->prepare(
            "INSERT INTO coach_invitations (organization_id, email, token_hash, created_by, created_at, expires_at)
             VALUES (:o, :e, :h, :c, '2030-02-28 10:00:00+00', '2030-03-08 10:00:00+00')",
        )->execute(['o' => $org, 'e' => $email, 'h' => CoachInvitationToken::hash($token), 'c' => $owner]);

        return [$org, $token];
    }

    private function activeMembershipsOf(string $coach): int
    {
        return (int) $this->scalar("SELECT count(*) FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $coach]);
    }

    public function testDeletingATrainerEndsTheMembershipsOfItsDeactivatedCoaches(): void
    {
        $this->deactivate($this->coach);
        self::assertSame(1, $this->activeMembershipsOf($this->coach), 'deactivating the Coach keeps the membership');

        $response = $this->deleteUser($this->trainer);

        self::assertSame(302, $response->getStatusCode(), $this->body($response));
        $row = $this->row('SELECT status, ended_at FROM coach_memberships WHERE coach_user_id = :c', ['c' => $this->coach]);
        self::assertSame('ended', $row['status'] ?? null);
        self::assertNotNull($row['ended_at'] ?? null);
    }

    public function testADeactivatedCoachOfADeletedTrainerCanAcceptANewInvitationAfterReactivation(): void
    {
        $this->deactivate($this->coach);
        $this->deleteUser($this->trainer);
        $this->reactivate($this->coach);
        [$org2, $token] = $this->otherTrainerInvites('coach@example.com');
        $this->signInWith('coach@example.com');

        $response = $this->post('/invitations/' . $token);

        self::assertSame(302, $response->getStatusCode(), $this->body($response));
        self::assertSame($org2, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $this->coach]));
        self::assertSame(1, $this->activeMembershipsOf($this->coach));
    }

    public function testACoachOfADeactivatedTrainerCanAcceptAnotherTrainersInvitationAndLeavesTheFirst(): void
    {
        $this->deactivate($this->trainer);
        [$org2, $token] = $this->otherTrainerInvites('coach@example.com');
        $this->signInWith('coach@example.com');

        $response = $this->post('/invitations/' . $token);

        self::assertSame(302, $response->getStatusCode(), $this->body($response));
        self::assertSame($org2, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $this->coach]));
        $old = $this->row("SELECT status, ended_at FROM coach_memberships WHERE coach_user_id = :c AND organization_id = :o", ['c' => $this->coach, 'o' => $this->org]);
        self::assertSame('ended', $old['status'] ?? null);
        self::assertNotNull($old['ended_at'] ?? null);
    }

    public function testACoachActiveUnderAnActiveTrainerIsStillRefusedAnotherInvitation(): void
    {
        [, $token] = $this->otherTrainerInvites('coach@example.com');
        $this->signInWith('coach@example.com');

        $response = $this->post('/invitations/' . $token);

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertSame($this->org, $this->scalar("SELECT organization_id FROM coach_memberships WHERE coach_user_id = :c AND status = 'active'", ['c' => $this->coach]));
    }

    public function testAnInvitationOfADeletedTrainerIsRefusedAndAssociatesNobody(): void
    {
        [$org2, $token] = $this->otherTrainerInvites('newcoach@example.com');
        $owner2 = (string) $this->scalar('SELECT owner_user_id FROM organizations WHERE id = :o', ['o' => $org2]);
        $this->deleteUser($owner2);
        $this->cookies = [];

        $response = $this->post('/invitations/' . $token, ['name' => 'New Coach', 'password' => 'a long coach password']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM coach_memberships WHERE organization_id = :o', ['o' => $org2]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM users WHERE email = 'newcoach@example.com'"));
    }
}
