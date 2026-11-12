<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Integration\Application\SendShareLinkByEmail;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Approval\ApprovalHttpTestCase;

/**
 * AC-86: every email the epic names has a template pair and is queued by its own use case, and what is
 * queued renders. The catalogue below is the complete list of this epic's emails (Q-01.04 may extend it);
 * a new `EmailMessage('...')` in src/ without an entry here fails the static check.
 */
#[Group('AC-86')]
final class EmailCatalogueTest extends ApprovalHttpTestCase
{
    /** Template name => [story, the use cases whose source must queue it]. */
    private const CATALOGUE = [
        'trainer-setup' => ['Trainer invite/credentials (US-01.01)', ['src/Admin/Application/CreateTrainer.php']],
        'registration-confirmation' => ['registration confirmation (US-01.05)', ['src/Onboarding/Application/RegisterViaShareLink.php', 'src/Onboarding/Application/RegisterFromPrefill.php']],
        'password-reset' => ['password reset', ['src/Identity/Application/RequestPasswordReset.php']],
        'email-verification' => ['email verification', ['src/Identity/Application/IssueEmailVerification.php']],
        'coach-invitation' => ['coach invitation (US-01.02)', ['src/Organization/Application/IssueCoachInvitation.php']],
        'coach-assignment' => ['coach assignment', ['src/Availability/Application/AssignCoach.php']],
        'approval-request' => ['parent approval request', ['src/Approval/Application/OpenApprovalRequest.php']],
        'approval-outcome' => ['approval outcome', ['src/Approval/Application/ApprovalNotifier.php']],
        'child-wants-to-join' => ['child-link notification', ['src/Onboarding/Application/BlockChildLink.php']],
        'token-spend-notice' => ['token-spend notice', ['src/Approval/Application/RequestTokenSpend.php']],
        'share-link' => ['ShareLink by email (AC-85)', ['src/Onboarding/Application/SendShareLinkByEmail.php']],
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testEveryCatalogueEmailHasATemplatePairAndIsQueuedByItsUseCase(): void
    {
        foreach (self::CATALOGUE as $template => [, $useCases]) {
            self::assertFileExists($this->root() . "/templates/email/{$template}.html.twig", $template);
            self::assertFileExists($this->root() . "/templates/email/{$template}.text.twig", $template);
            foreach ($useCases as $file) {
                self::assertStringContainsString("new EmailMessage('{$template}'", (string) file_get_contents($this->root() . '/' . $file), "$file queues $template");
            }
        }
    }

    public function testNoEmailIsQueuedWithoutBeingInTheCatalogue(): void
    {
        $queued = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php' && preg_match_all("/new EmailMessage\('([a-z-]+)'/", (string) file_get_contents($file->getPathname()), $m) > 0) {
                array_push($queued, ...$m[1]);
            }
        }

        self::assertSame([], array_values(array_diff(array_unique($queued), array_keys(self::CATALOGUE))), 'emails queued but missing from the catalogue');
        self::assertSame([], array_values(array_diff(array_keys(self::CATALOGUE), array_unique($queued))), 'catalogue emails nobody queues');
    }

    private function emailOf(string $userId): string
    {
        return (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $userId]);
    }

    private function signInAsUserId(string $userId): void
    {
        $this->cookies = [];
        $this->login($this->emailOf($userId));
    }

    /** Asserts the use case queued the email for the address, and that it renders as HTML and text with a subject. */
    private function assertSentAndRenders(string $template, string $to): void
    {
        $rows = $this->pdo->prepare("SELECT payload FROM outbox WHERE kind = 'email' AND topic = :t AND payload->>'to' = :to");
        $rows->execute(['t' => $template, 'to' => $to]);
        $payloads = $rows->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotSame([], $payloads, "$template was queued for $to");

        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        foreach ($payloads as $json) {
            /** @var array{to: string, context: array<string, mixed>} $payload */
            $payload = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
            $mail = $renderer->render($template, $payload['to'], $payload['context'], 'm1');
            self::assertNotSame('', $mail->subject, $template);
            self::assertNotSame('', trim($mail->text), $template);
            self::assertStringContainsString('<html', $mail->html);
            self::assertStringNotContainsString('{{', $mail->html . $mail->text);
        }
    }

    public function testApprovalRequestAndOutcomeEmailsAreSentToTheParent(): void
    {
        $this->signInAsKid();
        $this->purchase();
        $this->assertSentAndRenders('approval-request', self::PARENT_EMAIL);

        $this->signInAsParent();
        $id = (string) $this->requestRow()['id'];
        $this->post('/family/approvals/' . $id . '/decision', ['decision' => 'approve', 'note' => 'Have fun!']);

        $this->assertSentAndRenders('approval-outcome', self::KID_EMAIL);
    }

    public function testTokenSpendNoticeIsSentToTheParent(): void
    {
        $this->pdo->prepare('UPDATE player_profiles SET token_spend_without_approval = true WHERE id = :p')->execute(['p' => $this->mia]);
        $this->signInAsKid();

        $this->post('/family/requests/token-spend', ['event_ref' => self::EVENT, 'tokens' => '5', 'event_title' => 'Spring Camp']);

        $this->assertSentAndRenders('token-spend-notice', self::PARENT_EMAIL);
    }

    public function testChildLinkNotificationIsSentToTheParent(): void
    {
        [, $other] = $this->trainerWithOrganization('Beta Academy');
        [, $code] = $this->shareLink($other);
        $this->signInAsKid();

        $this->post('/join/' . $code);

        $this->assertSentAndRenders('child-wants-to-join', self::PARENT_EMAIL);
    }

    public function testRegistrationConfirmationAndEmailVerificationAreSentOnRegistration(): void
    {
        [, $code] = $this->shareLink($this->org);
        $this->cookies = [];

        $this->post('/join/' . $code . '/register', [
            'name' => 'Sam Newcomer', 'email' => 'sam@example.com', 'password' => 'a long enough passphrase', 'phone' => '+1 415 555 2671',
            'player_for' => 'me', 'player_name' => '', 'birth_date' => '1990-04-02', 'gender' => 'other',
        ]);

        $this->assertSentAndRenders('registration-confirmation', 'sam@example.com');
        $this->assertSentAndRenders('email-verification', 'sam@example.com');
    }

    public function testPasswordResetEmailIsSent(): void
    {
        $this->cookies = [];

        $this->post('/forgot-password', ['email' => self::PARENT_EMAIL]);

        $this->assertSentAndRenders('password-reset', self::PARENT_EMAIL);
    }

    public function testTrainerSetupEmailIsSentWhenAnAdminCreatesATrainer(): void
    {
        $this->signInAs('super_admin', 'ada@example.com', 'Ada Admin');

        $this->post('/admin/users/new', ['business_name' => 'Beta Academy', 'trainer_name' => 'Tim Trainer', 'email' => 'tim@example.com', 'phone' => '+1 415 555 2671']);

        $this->assertSentAndRenders('trainer-setup', 'tim@example.com');
    }

    public function testCoachInvitationAndCoachAssignmentEmailsAreSent(): void
    {
        $this->signInAsUserId($this->trainer);

        $this->post('/trainer/coaches/invite', ['email' => 'coach@example.com']);
        $this->assertSentAndRenders('coach-invitation', 'coach@example.com');

        $coach = $this->createUser('coach', 'casey@example.com', name: 'Casey Coach');
        $this->pdo->prepare('INSERT INTO coach_memberships (organization_id, coach_user_id) VALUES (:o, :c)')->execute(['o' => $this->org, 'c' => $coach]);
        $this->pdo->prepare('INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES (:t, :s, 0, :a, :b)')
            ->execute(['t' => 'coach', 's' => $coach, 'a' => '09:00', 'b' => '12:00']);
        $this->post('/trainer/assignments', ['event_ref' => 'EVT-100', 'event_date' => '2030-03-04', 'starts_at' => '10:00', 'ends_at' => '11:00', 'coach_user_id' => $coach]);
        $this->assertSentAndRenders('coach-assignment', 'casey@example.com');
    }

    public function testShareLinkEmailIsSentThroughThePort(): void
    {
        $sender = $this->container->get(SendShareLinkByEmail::class);
        self::assertInstanceOf(SendShareLinkByEmail::class, $sender);

        $sender->send(OrganizationId::fromString($this->org), 'friend@example.com', 'Fran Friend');

        $this->assertSentAndRenders('share-link', 'friend@example.com');
    }
}
