<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class ApprovalNotificationTest extends ApprovalHttpTestCase
{
    /** @return array{to: string, context: array<string, mixed>} */
    private function queuedEmail(): array
    {
        $row = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'approval-request'");
        self::assertIsArray($row, 'an approval-request email was queued');
        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    #[Group('AC-39')]
    public function testTheParentGetsAnInAppNotificationForANewRequest(): void
    {
        $this->purchase(self::EVENT, '25.00', ['event_title' => 'Spring Camp']);

        $row = $this->row("SELECT * FROM notifications WHERE kind = 'approval_requested'");
        self::assertIsArray($row);
        self::assertSame($this->parent, $row['user_id']);
        self::assertStringContainsString('Mia Smith asks for your approval: purchase', (string) $row['title']);
        self::assertStringContainsString('Spring Camp', (string) $row['body']);
        self::assertStringContainsString('USD 25.00', (string) $row['body']);
        self::assertNull($row['read_at']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], true);
        self::assertSame($this->requestRow()['id'], $payload['request_id']);
        // and nobody else is notified
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM notifications WHERE user_id <> :p', ['p' => $this->parent]));
    }

    #[Group('AC-39'), Group('AC-86')]
    public function testTheParentGetsAnEmailWithTheRequestDetailsAndAReviewLink(): void
    {
        $this->purchase(self::EVENT, '25.00', ['event_title' => 'Spring Camp', 'note' => 'Please!']);

        $payload = $this->queuedEmail();
        self::assertSame(self::PARENT_EMAIL, $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $mail = $renderer->render('approval-request', $payload['to'], $payload['context'], 'm1');
        self::assertSame('Mia Smith needs your approval: purchase', $mail->subject);
        foreach ([$mail->html, $mail->text] as $content) {
            self::assertStringContainsString('Spring Camp', $content);
            self::assertStringContainsString('USD 25.00', $content);
            self::assertStringContainsString('Ace Tennis Academy', $content);
            self::assertStringContainsString('Please!', $content);
            self::assertStringContainsString('2030-03-03 10:00 UTC', $content);
        }
        $url = (string) $payload['context']['review_url'];
        self::assertStringEndsWith('/family/approvals/' . $this->requestRow()['id'], $url);
        self::assertStringContainsString('href="' . $url . '"', $mail->html);
    }

    #[Group('AC-39'), Group('AC-48')]
    public function testAnRsvpRequestNotifiesTheParentToo(): void
    {
        $this->rsvp(self::EVENT, ['event_title' => 'Free Saturday Clinic']);

        $payload = $this->queuedEmail();
        self::assertSame('RSVP', $payload['context']['kind_label']);
        self::assertNull($payload['context']['amount_label']);
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_requested'"));
    }

    #[Group('AC-39')]
    public function testTheNotificationIsWrittenAtomicallyWithTheRequest(): void
    {
        self::assertSame(422, $this->purchase('0190aaaa-0000-7000-8000-0000000000ff')->getStatusCode());

        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'approval-request'"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'approval_requested'"));
    }
}
