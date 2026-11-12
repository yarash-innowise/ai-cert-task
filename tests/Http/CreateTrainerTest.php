<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class CreateTrainerTest extends AppHttpTestCase
{
    private const VALID = [
        'business_name' => 'Ace Tennis Academy',
        'trainer_name' => 'Tina Trainer',
        'email' => 'Tina@Example.com',
        'phone' => '+1 415 555 2671',
    ];

    /** @param array<string, string> $overrides */
    private function create(array $overrides = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/admin/users/new', [...self::VALID, ...$overrides]);
    }

    private function userCount(): int
    {
        return (int) $this->scalar('SELECT count(*) FROM users');
    }

    #[Group('AC-13')]
    #[Group('AC-16')]
    public function testSuperAdminCreatesATrainerWithAnOrganizationAndActiveStatus(): void
    {
        $this->signInAs('super_admin');

        $response = $this->create();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/users?notice=trainer_created', $this->location($response));
        $row = $this->row("SELECT id, name, phone, role, status, must_set_password, email_verified_at, password_hash FROM users WHERE email = 'tina@example.com'");
        self::assertIsArray($row);
        self::assertSame('Tina Trainer', $row['name']);
        self::assertSame('+14155552671', $row['phone']);
        self::assertSame('trainer', $row['role']);
        self::assertSame('active', $row['status']);
        self::assertTrue($row['must_set_password']);
        self::assertNull($row['email_verified_at']);
        self::assertNull($row['password_hash']);
        self::assertSame('Ace Tennis Academy', $this->scalar('SELECT business_name FROM organizations WHERE owner_user_id = :u', ['u' => $row['id']]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function requiredFields(): iterable
    {
        yield 'business name' => ['business_name', 'Business name is required.'];
        yield 'trainer name' => ['trainer_name', 'Trainer name is required.'];
        yield 'email' => ['email', 'Email is required.'];
        yield 'phone' => ['phone', 'Phone number is required.'];
    }

    #[Group('AC-13')]
    #[DataProvider('requiredFields')]
    public function testEachFieldIsRequiredAndTheMessageNamesTheField(string $field, string $message): void
    {
        $this->signInAs('super_admin');
        $before = $this->userCount();

        $response = $this->create([$field => '   ']);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString($message, $body);
        self::assertStringContainsString('href="#field-' . $field . '"', $body);
        self::assertSame($before, $this->userCount());
    }

    #[Group('AC-13')]
    public function testAnInvalidPhoneIsRefusedWithAMessageForThePhoneField(): void
    {
        $this->signInAs('super_admin');

        $response = $this->create(['phone' => '12']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-phone"', $this->body($response));
    }

    #[Group('AC-6')]
    #[Group('AC-12')]
    public function testADuplicateEmailInAnotherLetterCaseIsRefusedAndCreatesNothing(): void
    {
        $this->createUser('player', 'tina@example.com');
        $this->signInAs('super_admin');
        $users = $this->userCount();
        $orgs = (int) $this->scalar('SELECT count(*) FROM organizations');
        $outbox = (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'trainer-setup'");
        $audit = (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'trainer.created'");

        $response = $this->create(['email' => 'TINA@EXAMPLE.COM']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This email is already registered.', $this->body($response));
        self::assertSame($users, $this->userCount());
        self::assertSame($orgs, (int) $this->scalar('SELECT count(*) FROM organizations'));
        self::assertSame($outbox, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'trainer-setup'"));
        self::assertSame($audit, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'trainer.created'"));
    }

    #[Group('AC-14')]
    #[Group('AC-86')]
    public function testTheSetupEmailCarriesALinkAndNoPassword(): void
    {
        $this->signInAs('super_admin');
        $this->create();

        $payload = $this->scalar("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'trainer-setup'");
        self::assertIsString($payload);
        /** @var array{to: string, context: array<string, string>} $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tina@example.com', $data['to']);
        self::assertMatchesRegularExpression('#^https://localhost:18443/setup/[A-Za-z0-9_-]{43}$#', $data['context']['link']);
        self::assertArrayNotHasKey('password', $data['context']);

        $twig = $this->container->get(\Twig\Environment::class);
        \assert($twig instanceof \Twig\Environment);
        $mail = (new \App\Shared\Infrastructure\Mail\EmailRenderer($twig))->render('trainer-setup', $data['to'], $data['context'], 'id-1');
        self::assertStringContainsString($data['context']['link'], $mail->html);
        self::assertStringContainsString($data['context']['link'], $mail->text);
        self::assertStringContainsString('Ace Tennis Academy', $mail->text);
        self::assertStringNotContainsStringIgnoringCase('temporary password', $mail->text);
    }

    #[Group('AC-17')]
    #[Group('AC-87')]
    public function testTheCreationIsAuditedWithActorTimeUserIdAndBusinessName(): void
    {
        $adminId = $this->signInAs('super_admin');
        $this->create();

        $trainerId = $this->scalar("SELECT id FROM users WHERE email = 'tina@example.com'");
        $row = $this->pdo->prepare("SELECT actor_user_id, subject_id, subject_type, payload, occurred_at FROM audit_log WHERE action = 'trainer.created'");
        $row->execute();
        $audit = $row->fetch();
        self::assertIsArray($audit);
        self::assertSame($adminId, $audit['actor_user_id']);
        self::assertSame($trainerId, $audit['subject_id']);
        self::assertNotNull($audit['occurred_at']);
        /** @var array<string, string> $payload */
        $payload = json_decode((string) $audit['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Ace Tennis Academy', $payload['business_name']);
        self::assertStringNotContainsString('tina@example.com', (string) $audit['payload']);
    }

    #[Group('AC-12')]
    #[Group('AC-9')]
    #[DataProvider('nonAdminRoles')]
    public function testOnlyASuperAdminCanReachTheCreateTrainerForm(string $role): void
    {
        $this->signInAs($role);
        $before = $this->userCount();

        self::assertSame(403, $this->get('/admin/users/new')->getStatusCode());
        self::assertSame(403, $this->create()->getStatusCode());
        self::assertSame($before, $this->userCount());
    }

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'trainer' => ['trainer'];
        yield 'coach' => ['coach'];
        yield 'player' => ['player'];
    }

    #[Group('AC-12')]
    public function testThereIsNoSelfRegistrationRouteForTrainers(): void
    {
        self::assertSame(404, $this->get('/register/trainer')->getStatusCode());
        self::assertSame(404, $this->get('/trainers/new')->getStatusCode());
        $this->cookies = [];
        $response = $this->get('/admin/users/new');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($response));
    }
}
