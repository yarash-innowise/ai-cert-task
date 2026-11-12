<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Admin\LifecycleTestCase;
use Tests\Http\TenantIsolation\RecordingRouter;

/**
 * AC-87: the sensitive operations (Trainer creation, impersonation, deletion, coach-availability override) each
 * leave an audit row naming who did what to which record, and no role can edit or remove the log: the
 * application has no route for it and the database refuses UPDATE, DELETE and TRUNCATE.
 */
#[Group('AC-87')]
final class AuditCompletenessTest extends LifecycleTestCase
{
    /** @return array<string, mixed> */
    private function auditRow(string $action): array
    {
        $row = $this->row('SELECT * FROM audit_log WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => $action]);
        self::assertIsArray($row, "an audit row for $action exists");

        return $row;
    }

    public function testCreatingATrainerIsAudited(): void
    {
        $this->post('/admin/users/new', ['business_name' => 'Beta Academy', 'trainer_name' => 'Tim Trainer', 'email' => 'tim@example.com', 'phone' => '+1 415 555 2671']);

        $row = $this->auditRow('trainer.created');
        self::assertSame($this->admin, $row['actor_user_id']);
        self::assertSame($this->scalar("SELECT id FROM users WHERE email = 'tim@example.com'"), $row['subject_id']);
        self::assertStringContainsString('Beta Academy', (string) $row['payload']);
        self::assertStringNotContainsString('tim@example.com', (string) $row['payload'], 'IDs and the business name, not personal data (G5)');
    }

    public function testStartingAnImpersonationIsAudited(): void
    {
        $coach = $this->activeCoach();

        $this->post("/admin/users/{$coach}/impersonate");

        $row = $this->auditRow('impersonation.started');
        self::assertSame($this->admin, $row['actor_user_id']);
        self::assertSame($coach, $row['subject_id']);
    }

    public function testDeletingAUserIsAudited(): void
    {
        $this->deleteUser($this->parent);

        $row = $this->auditRow('user.deleted');
        self::assertSame($this->admin, $row['actor_user_id']);
        self::assertSame($this->parent, $row['subject_id']);
    }

    public function testACoachAvailabilityOverrideIsAudited(): void
    {
        $coach = $this->activeCoach('casey@example.com', 'Casey Coach');
        $this->pdo->prepare('INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES (:t, :s, 0, :a, :b)')
            ->execute(['t' => 'coach', 's' => $coach, 'a' => '09:00', 'b' => '12:00']);
        $this->cookies = [];
        $this->login($this->trainerEmail());

        // 2030-03-05 is a Tuesday: outside the Coach's Monday schedule, so the Trainer overrides with a reason
        $this->post('/trainer/assignments', ['event_ref' => 'EVT-100', 'event_date' => '2030-03-05', 'starts_at' => '10:00', 'ends_at' => '11:00', 'coach_user_id' => $coach, 'continue_anyway' => '1', 'reason' => 'The camp needs this Coach']);

        $row = $this->auditRow('coach.availability_override');
        self::assertSame($this->trainer, $row['actor_user_id']);
        self::assertSame($this->org, $row['organization_id']);
        /** @var array<string, string> $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($coach, $payload['coach_user_id']);
        self::assertSame('The camp needs this Coach', $this->scalar('SELECT reason FROM coach_availability_overrides WHERE id = :i', ['i' => $payload['override_id']]), 'the audit row points at the stored reason');
    }

    /** @return iterable<string, array{string}> */
    public static function roles(): iterable
    {
        yield 'super admin' => ['super_admin'];
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    #[DataProvider('roles')]
    public function testNoRoleCanEditOrRemoveTheLogThroughTheApplication(string $role): void
    {
        $this->deleteUser($this->parent);
        $id = (string) $this->auditRow('user.deleted')['id'];
        $before = (int) $this->scalar('SELECT count(*) FROM audit_log');
        $this->signInAs($role);

        foreach (['/admin/audit', '/audit', "/admin/audit/{$id}", "/audit/{$id}", "/admin/audit-log/{$id}"] as $path) {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $this->send($method, $path, ['_csrf' => $this->csrfToken(), 'action' => 'tampered']);
                self::assertContains($response->getStatusCode(), [403, 404, 405], "$role $method $path");
            }
        }
        self::assertSame($before, (int) $this->scalar('SELECT count(*) FROM audit_log'));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'tampered'"));
    }

    public function testTheRouteTableHasNoAuditRoute(): void
    {
        $router = new RecordingRouter();
        $routes = require __DIR__ . '/../../config/routes.php';
        $routes($router, $this->container);

        $audit = array_filter($router->table(), static fn(string $route): bool => stripos($route, 'audit') !== false);

        self::assertSame([], array_values($audit));
    }

    public function testTheDatabaseRefusesUpdateDeleteAndTruncate(): void
    {
        $this->deleteUser($this->parent);
        $id = (string) $this->auditRow('user.deleted')['id'];

        foreach (['UPDATE audit_log SET action = \'tampered\' WHERE id = ' . $id, 'DELETE FROM audit_log WHERE id = ' . $id, 'TRUNCATE audit_log'] as $sql) {
            $this->pdo->exec('SAVEPOINT attempt');
            try {
                $this->pdo->exec($sql);
                self::fail("$sql was allowed");
            } catch (\PDOException) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT attempt');
            }
        }
        self::assertSame('user.deleted', $this->auditRow('user.deleted')['action']);
    }
}
