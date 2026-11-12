<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

/** AC-49: a logged-in child opens a Trainer's link. */
final class ChildLinkBlockTest extends OnboardingHttpTestCase
{
    private string $org;
    private string $code;
    private string $linkId;
    private string $parent;
    private string $parentEmail = 'parent@example.com';
    private string $mia;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->org] = $this->trainerWithOrganization("Ace Tennis Academy");
        [$this->linkId, $this->code] = $this->shareLink($this->org);
        $this->parent = $this->signInAs('player', $this->parentEmail, 'Pat Parent');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Smith');
    }

    /** @return array{to: string, context: array<string, mixed>} */
    private function queuedEmail(): array
    {
        $row = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'child-wants-to-join'");
        self::assertIsArray($row, 'an email was queued');

        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    #[Group('AC-49')]
    public function testTheChildIsToldToAskTheParentAndIsNotAssociated(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);
        $associations = (int) $this->scalar('SELECT count(*) FROM player_trainer_associations');

        $response = $this->post('/join/' . $this->code);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ask your parent to register you with this trainer', $this->body($response));
        self::assertSame($associations, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
        self::assertSame(0, $this->activeAssociations($this->mia));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM share_link_uses'));
        self::assertSame(0, (int) $this->scalar('SELECT use_count FROM share_links WHERE id = :i', ['i' => $this->linkId]));
    }

    #[Group('AC-49'), Group('AC-86')]
    public function testTheParentIsEmailedWithTheExactSubjectAndAReviewButton(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);

        $this->post('/join/' . $this->code);

        $payload = $this->queuedEmail();
        self::assertSame($this->parentEmail, $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $mail = $renderer->render('child-wants-to-join', $payload['to'], $payload['context'], 'm1');
        self::assertSame("Mia Smith wants to join Ace Tennis Academy's program", $mail->subject);
        self::assertStringContainsString('Review Registration', $mail->html);
        $url = (string) $payload['context']['review_url'];
        self::assertStringEndsWith('/family/join/' . $this->code . '?child=' . $this->mia, $url);
        self::assertStringContainsString('href="' . $url . '"', $mail->html);
        self::assertStringContainsString($url, $mail->text);
    }

    #[Group('AC-49')]
    public function testTheParentGetsAnInAppNotificationToo(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);

        $this->post('/join/' . $this->code);

        $notification = $this->row('SELECT user_id, kind, title FROM notifications');
        self::assertSame($this->parent, $notification['user_id'] ?? null);
        self::assertSame("Mia Smith wants to join Ace Tennis Academy's program", $notification['title'] ?? null);
    }

    #[Group('AC-49')]
    public function testTheParentReviewPageRegistersTheChildOnConfirm(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);
        $this->post('/join/' . $this->code);
        $this->cookies = [];
        $this->login($this->parentEmail);

        $page = $this->get('/family/join/' . $this->code . '?child=' . $this->mia);
        $confirm = $this->post('/family/join/' . $this->code, ['child' => $this->mia]);

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Register Mia with Ace Tennis Academy', $this->body($page));
        self::assertSame(302, $confirm->getStatusCode());
        self::assertSame(1, $this->activeAssociations($this->mia));
        self::assertSame('profile_added', $this->scalar('SELECT outcome FROM share_link_uses'));
    }

    #[Group('AC-49')]
    public function testOnlyTheOwningParentCanOpenOrConfirmTheReview(): void
    {
        $other = $this->signInAs('player', 'other@example.com');
        $before = $this->writeFootprint();

        $get = $this->get('/family/join/' . $this->code . '?child=' . $this->mia);
        $post = $this->post('/family/join/' . $this->code, ['child' => $this->mia]);
        $missing = $this->get('/family/join/' . $this->code);

        self::assertSame(404, $get->getStatusCode());
        self::assertSame(404, $post->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame($before, $this->writeFootprint());
        self::assertNotSame($other, $this->parent);

        $this->cookies = [];
        self::assertStringStartsWith('/login', $this->location($this->get('/family/join/' . $this->code . '?child=' . $this->mia)));
    }

    #[Group('AC-49'), Group('AC-26')]
    public function testTheReviewOfAnInvalidLinkShowsTheGenericMessage(): void
    {
        $response = $this->get('/family/join/0000000000000000000000?child=' . $this->mia);
        $post = $this->post('/family/join/0000000000000000000000', ['child' => $this->mia]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('This link is not valid', $this->body($response));
        self::assertSame(404, $post->getStatusCode());
        self::assertSame(0, $this->activeAssociations($this->mia));
    }

    #[Group('AC-49')]
    public function testAnInvalidLinkOpenedByAChildNotifiesNobody(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);
        $before = $this->writeFootprint();

        $response = $this->post('/join/0000000000000000000000');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame($before, $this->writeFootprint());
    }

    #[Group('AC-49')]
    public function testNoEmailIsQueuedWhenTheGuardianIsInactive(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);
        $this->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :i")->execute(['i' => $this->parent]);

        $response = $this->post('/join/' . $this->code);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'child-wants-to-join'"));
    }
}
