<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

final class DeleteUserTest extends LifecycleTestCase
{
    #[Group('AC-76')]
    public function testTheWarningPageNamesTheUserAndTheRoleAndAsksForAReason(): void
    {
        $page = $this->get("/admin/users/{$this->parent}/delete");

        self::assertSame(200, $page->getStatusCode());
        $html = $this->body($page);
        self::assertStringContainsString('Delete Pat Parent?', $html);
        self::assertStringContainsString('This cannot be undone', $html);
        self::assertStringContainsString('name="reason"', $html);
        self::assertSame('active', $this->statusOf($this->parent));
    }

    #[Group('AC-79')]
    public function testAReasonIsRequiredAndNothingChangesWithoutIt(): void
    {
        $response = $this->deleteUser($this->parent, '   ');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter the reason for the deletion.', $this->body($response));
        self::assertStringContainsString('href="#field-reason"', $this->body($response));
        self::assertSame('active', $this->statusOf($this->parent));
        self::assertSame(self::PARENT_EMAIL, $this->emailOf($this->parent));
    }

    #[Group('AC-76'), Group('AC-78')]
    public function testDeletingAnonymizesTheUserAndMarksItDeleted(): void
    {
        $response = $this->deleteUser($this->parent);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=user_deleted', $this->location($response));
        $row = $this->row('SELECT name, email, phone, password_hash, status FROM users WHERE id = :i', ['i' => $this->parent]);
        self::assertIsArray($row);
        self::assertSame('Deleted User', $row['name']);
        self::assertSame('deleted_' . $this->parent . '@example.com', $row['email']);
        self::assertNull($row['phone']);
        self::assertNull($row['password_hash']);
        self::assertSame('deleted', $row['status']);
    }

    #[Group('AC-78')]
    public function testADeletedUserCannotSignInAndItsSessionsAreGone(): void
    {
        $jar = $this->signInWith(self::PARENT_EMAIL);
        $this->signInWith(self::ADMIN_EMAIL);

        $this->deleteUser($this->parent);

        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $this->parent]));
        self::assertSame(302, $this->get('/family', [], $jar)->getStatusCode());
        $this->cookies = [];
        self::assertSame(401, $this->login(self::PARENT_EMAIL)->getStatusCode());
        self::assertSame(401, $this->login('deleted_' . $this->parent . '@example.com')->getStatusCode());
        self::assertArrayNotHasKey('__Host-sid', $this->cookies);
    }

    #[Group('AC-79'), Group('AC-87')]
    public function testTheAuditEntryHoldsTheOriginalIdTheDeleterAndTheReasonButNoOriginalData(): void
    {
        $this->deleteUser($this->parent, 'Privacy request 42.');

        $audit = $this->row("SELECT actor_user_id, subject_id, occurred_at, payload FROM audit_log WHERE action = 'user.deleted'");
        self::assertIsArray($audit);
        self::assertSame($this->admin, $audit['actor_user_id']);
        self::assertSame($this->parent, $audit['subject_id']);
        self::assertNotEmpty($audit['occurred_at']);
        $payload = json_decode((string) $audit['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->parent, $payload['original_user_id']);
        self::assertSame('Privacy request 42.', $payload['reason']);
        self::assertStringNotContainsString('parent@example.com', (string) $audit['payload']);
        self::assertStringNotContainsString('Pat Parent', (string) $audit['payload']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'user.deleted'"));
    }

    #[Group('AC-87')]
    public function testTheDeletionAuditEntryCannotBeEditedOrRemoved(): void
    {
        $this->deleteUser($this->parent);
        $id = (int) $this->scalar("SELECT id FROM audit_log WHERE action = 'user.deleted'");

        foreach (["UPDATE audit_log SET payload = '{}' WHERE id = {$id}", "DELETE FROM audit_log WHERE id = {$id}"] as $sql) {
            $this->pdo->exec('SAVEPOINT attempt');
            try {
                $this->pdo->exec($sql);
                self::fail('The audit log accepted a change: ' . $sql);
            } catch (\PDOException) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT attempt');
            }
        }
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE id = {$id}"));
    }

    #[Group('AC-76')]
    public function testATrainerWithActiveCoachesCannotBeDeletedUntilTheyAreDeactivated(): void
    {
        $coach = $this->activeCoach();

        $refused = $this->deleteUser($this->trainer);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('still has active coaches', $this->body($refused));
        self::assertSame('active', $this->statusOf($this->trainer));
        self::assertSame('Ace Tennis Academy', $this->scalar('SELECT business_name FROM organizations WHERE id = :o', ['o' => $this->org]));

        $this->deactivate($coach);
        self::assertSame(302, $this->deleteUser($this->trainer)->getStatusCode());
        self::assertSame('deleted', $this->statusOf($this->trainer));
        self::assertSame('inactive', $this->scalar('SELECT status FROM organizations WHERE id = :o', ['o' => $this->org]));
    }

    #[Group('AC-76')]
    public function testADeletedUserIsNotDeletedTwiceAndCannotBeEdited(): void
    {
        $this->deleteUser($this->parent);

        $again = $this->deleteUser($this->parent);

        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('already deleted', $this->body($again));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'user.deleted'"));
    }

    #[Group('AC-76')]
    public function testOnlyASuperAdminMayDelete(): void
    {
        $this->signInAs('trainer', 'other.trainer@example.com');

        self::assertSame(403, $this->get("/admin/users/{$this->parent}/delete")->getStatusCode());
        self::assertSame(403, $this->deleteUser($this->parent)->getStatusCode());
        self::assertSame('active', $this->statusOf($this->parent));
    }
}
